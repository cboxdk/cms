<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupRepeat;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeContributor;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\RichTextLink;
use Cbox\Cms\Generators\Schema\Domain\RichTextList;
use Cbox\Cms\Generators\Schema\Domain\RichTextMark;
use Cbox\Cms\Generators\Schema\Domain\RichTextStyle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use Cbox\Cms\Generators\Tests\Schema\Fakes\ColourFieldType;
use Cbox\Cms\Generators\Tests\Schema\Fakes\ColourOptions;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeFieldTypeContributor;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Composer\InstalledVersions;
use PHPUnit\Framework\Assert;
use RuntimeException;

/*
 * The blueprint reader of PRD 11.12 on real files: YAML read with symfony/yaml, validated against
 * blueprint.v1.json in the installed cboxdk/cms with opis' CompliantValidator, and mapped
 * into the typed model. The fixtures of T40's Codecs test in packages/contracts are the reference:
 * every valid one reads into the model, and every invalid one fails at the JSON pointer it breaks.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const MONOREPO = __DIR__.'/../../../..';

/**
 * A new root of the owner below a scratch base, with the given files.
 *
 * @param  array<string, string>  $files  contents by path below the root
 */
function blueprintRoot(array $files = [], string $owner = 'app', string $directory = 'schema', ?string $base = null): SchemaRoot
{
    $root = new SchemaRoot(new Owner($owner), $base ?? SchemaFixtures::scratch(), $directory);
    mkdir($root->path(), 0o775, true);

    foreach ($files as $path => $contents) {
        SchemaFixtures::write($root->path().'/'.$path, $contents);
    }

    return $root;
}

function contractFixture(string $path): string
{
    return (string) file_get_contents(BlueprintFixtures::CONTRACT_FIXTURES.'/'.$path);
}

/**
 * The YAML source with the core's field types and those of the other contributors given.
 */
function yamlBlueprints(?BlueprintSchemaFile $schema = null, FieldTypeContributor ...$contributors): YamlBlueprintSource
{
    return new YamlBlueprintSource($schema ?? new BlueprintSchemaFile, new BlueprintDocumentReader(new FieldTypeRegistry(new CoreFieldTypes, ...$contributors)), new BlueprintRules);
}

/**
 * A root of acme with the type that T40's valid extension extends.
 */
function extendedProductRoot(): SchemaRoot
{
    $acme = blueprintRoot([], 'acme', 'vendor/acme/shop/schema');
    SchemaFixtures::write($acme->path().'/product.yaml', BlueprintFixtures::yaml(BlueprintFixtures::type($acme, 'product.yaml', '0192a3b4-c5d6-7e8f-9a0b-aaaaaaaaaaaa', 'product')));

    return $acme;
}

/**
 * @param  list<SchemaRoot>  $roots
 */
function blueprintFailure(array $roots, ?BlueprintSchemaFile $schema = null): GenerationFailed
{
    try {
        yamlBlueprints($schema)->read($roots);
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The reader accepted the files.');
}

/**
 * The codes of the problems, each once.
 *
 * @return list<string>
 */
function problemCodes(GenerationFailed $failed): array
{
    return array_values(array_unique(array_map(static fn (GenerateErrorCode $code): string => $code->value, $failed->codes())));
}

/**
 * The JSON pointers that the problems name, each once, in their order.
 *
 * @param  list<GenerationProblem>  $problems
 * @return list<string>
 */
function problemPointers(array $problems, string $file): array
{
    return array_values(array_unique(array_map(static function (GenerationProblem $problem) use ($file): string {
        expect(str_starts_with($problem->message, $file.', '))->toBeTrue($problem->message);

        return explode(': ', substr($problem->message, strlen($file) + 2), 2)[0];
    }, $problems)));
}

/**
 * A copy of the installed blueprint schema changed as a later release of cboxdk/cms
 * could change it, to stand in for that release: the value at the JSON pointer is replaced, or
 * the value is appended to the list there.
 */
function laterBlueprintSchema(string $pointer, mixed $value, bool $append = false): BlueprintSchemaFile
{
    $schema = json_decode((string) file_get_contents(new BlueprintSchemaFile()->path()), true, 512, JSON_THROW_ON_ERROR);
    $node = &$schema;

    foreach (array_slice(explode('/', $pointer), 1) as $segment) {
        if (! is_array($node)) {
            throw new RuntimeException('The blueprint schema has no '.$pointer.'.');
        }

        $node = &$node[$segment];
    }

    if ($append && ! is_array($node)) {
        throw new RuntimeException('The blueprint schema has no list at '.$pointer.'.');
    }

    if ($append) {
        $node[] = $value;
    } else {
        $node = $value;
    }

    unset($node);
    $path = SchemaFixtures::scratch().'/blueprint.v1.json';
    SchemaFixtures::write($path, json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return new BlueprintSchemaFile($path);
}

/**
 * Each invalid fixture of T40 with the JSON pointer it breaks.
 *
 * @return array<string, array{string, string}>
 */
function invalidContractFixtures(): array
{
    return [
        'a handle with an uppercase letter' => ['handle-uppercase.yaml', '/fields/0/handle'],
        'a handle with a double underscore' => ['handle-double-underscore.yaml', '/fields/0/handle'],
        'the reserved handle ext' => ['handle-ext.yaml', '/fields/0/handle'],
        'a handle with the reserved prefix cms_' => ['handle-cms-prefix.yaml', '/fields/0/handle'],
        'a top-level field without a classification' => ['missing-classification.yaml', '/fields/0'],
        'a public field without a description and without agents: false' => ['missing-description.yaml', '/fields/0'],
        'a confidential field with agents: true and without a description' => ['missing-description-agents-true.yaml', '/fields/0'],
        'a field without a description in a public group' => ['missing-description-in-group.yaml', '/fields/0/fields/0'],
        'a personal field, which cannot declare its purpose, legal basis, retention, recipients and subject' => ['classification-personal.yaml', '/fields/0/classification'],
        'a sensitive field, which cannot declare its purpose, legal basis, retention, recipients and subject' => ['classification-sensitive.yaml', '/fields/0/classification'],
        'history: audit_only' => ['history-audit-only-underscore.yaml', '/capabilities/history'],
        'an unquoted date in min' => ['unquoted-date.yaml', '/fields/1/min'],
        'a datetime in min without its offset' => ['datetime-without-offset.yaml', '/fields/1/min'],
        'a decimal without scale' => ['decimal-without-scale.yaml', '/fields/0'],
        'a classification on a field inside a group' => ['classification-in-group.yaml', '/fields/0/fields/0'],
        'a repeated group with min_items above 500, the most a repeated field holds' => ['repeat-min-items-above-500.yaml', '/fields/0/repeat/min_items'],
        'the field type relation' => ['field-type-relation.yaml', '/fields/0/type'],
        'an addon field type in the reserved namespace app' => ['field-type-app-namespace.yaml', '/fields/0/type'],
        'an addon field type in the reserved namespace ext' => ['field-type-ext-namespace.yaml', '/fields/0/type'],
        'kind: fieldset' => ['kind-fieldset.yaml', '/kind'],
        'localization: variants' => ['localization-variants.yaml', '/capabilities/localization'],
        'an extension without fields' => ['extension-without-fields.yaml', '/'],
        'a missing blueprint' => ['missing-blueprint.yaml', '/'],
    ];
}

/**
 * The article of T40's valid fixtures as the model holds it.
 */
function expectedArticle(SchemaRoot $root): TypeBlueprint
{
    $app = $root->owner;
    $at = new SourceLocation('schema/article.yaml', '');
    $field = static fn (int $index, string $handle, string $label, string $description, FieldOptions $options, ?Classification $classification, bool $required = false, bool $filterable = false, bool $sortable = false, bool $agents = true, ?SourceLocation $location = null): FieldBlueprint => new FieldBlueprint(
        new Handle($handle),
        $label,
        $description === '' ? null : $description,
        $required,
        $classification,
        $filterable,
        $sortable,
        $agents,
        $options,
        $app,
        $location ?? $at->below('fields', $index),
    );

    return new TypeBlueprint(
        TypeId::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b'),
        new Handle('article'),
        'Article',
        'A news article with a headline, a body and its credits.',
        1,
        new Capabilities(History::Full, Stages::DraftRelease, Localization::None, true),
        [
            $field(0, 'title', 'Headline', 'The headline as it is shown on the page and in lists.', new TextOptions(null, 120, TextFormat::Plain), Classification::Public, required: true, sortable: true),
            $field(1, 'summary', 'Summary', 'A short plain-text summary for lists and search results.', new LongTextOptions(null, 500), Classification::Public),
            $field(2, 'reading_minutes', 'Reading time', 'The estimated reading time in whole minutes.', new IntegerOptions(1, 120, 'min'), Classification::Public, filterable: true),
            $field(3, 'rating', 'Rating', "The editors' rating of the article, from 0 to 5.", new DecimalOptions(3, 2, new DecimalBound('0'), new DecimalBound('5.00'), null), Classification::Internal),
            $field(4, 'featured', 'Featured', 'Whether the article is shown on the front page.', new BooleanOptions, Classification::Public, filterable: true),
            $field(5, 'event_date', 'Event date', 'The date of the event the article covers.', new DateOptions(new BlueprintDate('2000-01-01'), null), Classification::Public, sortable: true),
            $field(6, 'embargo_until', 'Embargo until', 'The time before which the article may not be published.', new DatetimeOptions(new BlueprintDatetime('2000-01-01T00:00:00Z'), null), Classification::Internal),
            $field(7, 'section', 'Section', 'The sections of the site the article is listed under.', new SelectOptions([
                new SelectOption(new Handle('news'), 'News'),
                new SelectOption(new Handle('sport'), 'Sport'),
                new SelectOption(new Handle('culture'), 'Culture'),
            ], true, 1, 2), Classification::Public, filterable: true),
            $field(8, 'body', 'Body', 'The body text of the article.', new RichTextOptions(
                [RichTextStyle::Normal, RichTextStyle::H2, RichTextStyle::H3, RichTextStyle::Blockquote],
                [RichTextMark::Strong, RichTextMark::Em],
                [RichTextList::Bullet, RichTextList::Number],
                [RichTextLink::Url],
            ), Classification::Public),
            $field(9, 'credits', 'Credits', 'The people who made the article, in the order they are credited.', new GroupOptions([
                $field(0, 'name', 'Name', 'The name of the person as it is printed.', new TextOptions(null, 100, TextFormat::Plain), null, required: true, agents: false, location: $at->below('fields', 9, 'fields', 0)),
                $field(1, 'role', 'Role', '', new TextOptions(null, 50, TextFormat::Plain), null, agents: false, location: $at->below('fields', 9, 'fields', 1)),
            ], new GroupRepeat(1, 10)), Classification::Confidential, agents: false),
        ],
        $app,
        $at,
    );
}

it('is the blueprint source in the container', function (): void {
    expect(app(BlueprintSource::class))->toBeInstanceOf(YamlBlueprintSource::class);
});

it('validates against blueprint.v1.json in the installed cboxdk/cms, in place', function (): void {
    $path = new BlueprintSchemaFile()->path();

    expect(realpath($path))->toBe(realpath(MONOREPO.'/packages/contracts/resources/schemas/blueprint.v1.json'))
        ->and(str_starts_with($path, (string) InstalledVersions::getInstallPath('cboxdk/cms')))->toBeTrue();
});

it('validates against the schema file it is given, not a copy of its own', function (): void {
    $root = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml')]);
    $stricter = laterBlueprintSchema('/$defs/label/maxLength', 5);

    expect(yamlBlueprints()->read([$root])->types)->toHaveCount(1)
        ->and(problemPointers(blueprintFailure([$root], $stricter)->problems, 'schema/article.yaml'))->toContain('/label', '/fields/0/label');
});

it('reads the valid article of T40 into the model', function (): void {
    $root = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml')]);

    expect(yamlBlueprints()->read([$root])->types)->toEqual([expectedArticle($root)]);
});

it('reads the valid extension of T40 into the model', function (): void {
    $root = blueprintRoot(['extension.yaml' => contractFixture('valid/extension.yaml')]);
    $at = new SourceLocation('schema/extension.yaml', '');

    expect(yamlBlueprints()->read([$root, extendedProductRoot()])->extensions)->toEqual([new ExtensionBlueprint(
        TypeId::fromString('0192a3b4-c5d6-7e8f-9a0b-aaaaaaaaaaaa'),
        1,
        [new FieldBlueprint(new Handle('tax_code'), 'Tax code', "The customer's tax code for the product.", false, Classification::Internal, false, false, true, new TextOptions(null, 20, TextFormat::Plain), Owner::app(), $at->below('fields', 0))],
        Owner::app(),
        $at,
    )]);
});

it('reads the addon field type of T40 through the field type that another contributor registers, as the core registers its own', function (): void {
    $root = blueprintRoot(['product.yaml' => contractFixture('valid/addon-field-type.yaml')], 'acme', 'vendor/acme/shop/schema');

    $product = yamlBlueprints(null, FakeFieldTypeContributor::acme(new ColourFieldType))->read([$root])->types[0];
    $colour = $product->fields[1];

    expect($product->owner->value)->toBe('acme')
        ->and($product->capabilities)->toEqual(new Capabilities(History::AuditOnly, Stages::None, Localization::None, false))
        ->and($colour->location)->toEqual(new SourceLocation('vendor/acme/shop/schema/product.yaml', '/fields/1'))
        ->and($colour->options)->toEqual(new ColourOptions('shop', false))
        ->and($colour->options->typeName())->toBe('acme:colour');
});

it('reads a model it writes back into the same model', function (): void {
    $root = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml'), 'product.yaml' => contractFixture('valid/addon-field-type.yaml'), 'extension.yaml' => contractFixture('valid/extension.yaml')]);
    $blueprints = yamlBlueprints(null, FakeFieldTypeContributor::acme(new ColourFieldType));
    $read = $blueprints->read([$root, extendedProductRoot()]);
    $again = ['app' => blueprintRoot(), 'acme' => blueprintRoot([], 'acme', 'vendor/acme/shop/schema')];

    foreach ([...$read->types, ...$read->extensions] as $blueprint) {
        SchemaFixtures::write($again[$blueprint->owner->value]->path().'/'.basename($blueprint->location->file), BlueprintFixtures::yaml($blueprint));
    }

    expect($read->types)->toHaveCount(3)
        ->and($read->extensions)->toHaveCount(1)
        ->and($blueprints->read(array_values($again)))->toEqual($read);
});

it('rejects each invalid fixture of T40 with generate_schema_invalid at the JSON pointer it breaks', function (string $fixture, string $pointer): void {
    $failed = blueprintFailure([blueprintRoot([$fixture => contractFixture('invalid/'.$fixture)])]);

    expect(problemCodes($failed))->toBe(['generate_schema_invalid'])
        ->and(problemPointers($failed->problems, 'schema/'.$fixture))->toBe([$pointer]);
})->with(invalidContractFixtures());

it('rejects blueprint: 2 with generate_schema_unsupported_version, asking for a newer cboxdk/cms', function (): void {
    $failed = blueprintFailure([blueprintRoot(['future.yaml' => contractFixture('invalid/blueprint-2.yaml')])]);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaUnsupportedVersion])
        ->and($failed->problems[0]->message)->toStartWith('schema/future.yaml, /blueprint: ')
        ->and($failed->problems[0]->message)->toContain('needs a newer cboxdk/cms');
});

it('has a case for every invalid fixture of T40', function (): void {
    $files = array_map(basename(...), glob(BlueprintFixtures::CONTRACT_FIXTURES.'/invalid/*.yaml') ?: []);
    $cases = [...array_map(static fn (array $case): string => $case[0], array_values(invalidContractFixtures())), 'blueprint-2.yaml'];
    sort($cases);

    expect($files)->toBe($cases);
});

it('reports every invalid file of every root in one run', function (): void {
    $base = SchemaFixtures::scratch();
    $app = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml'), 'bad.yaml' => contractFixture('invalid/handle-uppercase.yaml')], base: $base);
    $acme = blueprintRoot(['types/kind.yaml' => contractFixture('invalid/kind-fieldset.yaml')], 'acme', 'vendor/acme/shop/schema', $base);

    $failed = blueprintFailure([$app, $acme]);

    expect(problemCodes($failed))->toBe(['generate_schema_invalid'])
        ->and(problemPointers([$failed->problems[0]], 'schema/bad.yaml'))->toBe(['/fields/0/handle'])
        ->and(problemPointers(array_slice($failed->problems, 1), 'vendor/acme/shop/schema/types/kind.yaml'))->toBe(['/kind']);
});

it('rejects YAML that is not plain data with generate_schema_invalid and its line', function (string $yaml, string $message): void {
    $failed = blueprintFailure([blueprintRoot(['article.yaml' => $yaml])]);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->problems[0]->message)->toStartWith('schema/article.yaml: not valid YAML: ')
        ->and($failed->problems[0]->message)->toContain($message);
})->with([
    'a duplicate key' => ["blueprint: 1\nkind: type\nhandle: article\nhandle: page\n", 'Duplicate key "handle" detected at line 4'],
    'a duplicate key in a field' => ["blueprint: 1\nkind: type\nfields:\n  - handle: title\n    label: Title\n    label: Headline\n    type: text\n", 'Duplicate key "label" detected at line 6'],
    'a duplicate key in a select option' => ["fields:\n  - handle: section\n    label: Section\n    options:\n      - value: news\n        value: sport\n        label: News\n    type: select\n", 'Duplicate key "value" detected at line 6'],
    'a duplicate key that repeats an earlier line' => ["fields:\n  - handle: title\n    label: Title\n    label: Title\n    type: text\n", 'Duplicate key "label" detected at line 4'],
    'a duplicate key on a dash line' => ["fields:\n  -\n    handle: title\n    handle: body\n", 'Duplicate key "handle" detected at line 4'],
    'a custom tag' => ["blueprint: 1\nkind: type\nhandle: !upper article\n", '"!upper" at line 3'],
    'a custom tag in a field' => ["blueprint: 1\nkind: type\nfields:\n  - handle: title\n    label: !upper title\n    type: text\n", '"!upper" at line 5'],
    'a PHP object' => ["blueprint: 1\nkind: !php/object 'O:8:\"stdClass\":0:{}'\n", 'at line 2'],
    'a PHP constant' => ["blueprint: 1\nkind: !php/const PHP_EOL\n", 'at line 2'],
    'bad indentation' => ["blueprint: 1\n  kind: type\n", 'at line 2'],
]);

it('rejects an empty file and a file that is not a mapping at the document', function (string $yaml): void {
    $failed = blueprintFailure([blueprintRoot(['empty.yaml' => $yaml])]);

    expect(problemCodes($failed))->toBe(['generate_schema_invalid'])
        ->and(problemPointers($failed->problems, 'schema/empty.yaml'))->toBe(['/']);
})->with(['empty' => '', 'a list' => "- blueprint: 1\n", 'an empty mapping' => "{}\n"]);

it('reads only *.yaml files', function (): void {
    $root = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml'), 'notes.md' => "# Notes\n", 'draft.yml' => "blueprint: 2\n", 'backup.yaml.bak' => "blueprint: 2\n"]);

    expect(yamlBlueprints()->read([$root])->types)->toHaveCount(1);
});

it('reads an integer written with a zero fraction as the integer', function (): void {
    $root = blueprintRoot(['article.yaml' => str_replace("version: 1\n", "version: 1.0\n", contractFixture('valid/article.yaml'))]);

    expect(yamlBlueprints()->read([$root])->types[0]->version)->toBe(1);
});

it('rejects a value the installed schema allows and this generator does not know, as needing a newer cboxdk/cms', function (string $schemaPointer, mixed $value, bool $append, string $yaml, string $pointer): void {
    $failed = blueprintFailure([blueprintRoot(['article.yaml' => $yaml])], laterBlueprintSchema($schemaPointer, $value, $append));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaUnsupportedVersion])
        ->and(problemPointers($failed->problems, 'schema/article.yaml'))->toBe([$pointer])
        ->and($failed->problems[0]->message)->toContain('needs a newer cboxdk/cms');
})->with([
    'a new capability value' => [
        '/$defs/capabilities/properties/localization/enum', 'variants', true,
        str_replace('localization: none', 'localization: variants', contractFixture('valid/article.yaml')),
        '/capabilities/localization',
    ],
    'a new core field type' => [
        '/$defs/field/properties/type/anyOf/0/enum', 'money', true,
        str_replace("type: boolean\n", "type: money\n", contractFixture('valid/article.yaml')),
        '/fields/4/type',
    ],
    'a new option of a field type' => [
        '/$defs/textOptions/properties/pattern', ['type' => 'string'], false,
        str_replace("    max_length: 120\n", "    max_length: 120\n    pattern: '^[A-Z]'\n", contractFixture('valid/article.yaml')),
        '/fields/0/pattern',
    ],
    'a new kind' => [
        '/properties/kind/enum', 'fieldset', true,
        "blueprint: 1\nkind: fieldset\n",
        '/kind',
    ],
    'a new form of a decimal bound' => [
        '/$defs/decimalOptions/properties/min/pattern', '^[-0-9.e]+$', false,
        str_replace("    min: '0'\n", "    min: '1e0'\n", contractFixture('valid/article.yaml')),
        '/fields/3/min',
    ],
    'a new form of a date bound' => [
        '/$defs/dateOptions/properties/min/format', 'regex', false,
        str_replace("    min: '2000-01-01'\n", "    min: '2000-1-1'\n", contractFixture('valid/article.yaml')),
        '/fields/5/min',
    ],
    'a new form of a datetime bound' => [
        '/$defs/datetimeOptions/properties/min/pattern', '.*', false,
        str_replace("    min: '2000-01-01T00:00:00Z'\n", "    min: '2000-01-01T00:00:00'\n", contractFixture('valid/article.yaml')),
        '/fields/6/min',
    ],
]);

it('refuses roots that lie in each other, so no file is read twice', function (): void {
    $base = SchemaFixtures::scratch();
    $app = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml')], base: $base);
    $inner = new SchemaRoot(new Owner('acme'), $base, 'schema/acme');
    mkdir($inner->path());

    $failed = blueprintFailure([$app, $inner]);
    $twice = blueprintFailure([$app, $app]);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->getMessage())->toContain($inner->path().' of acme lies in the schema root '.$app->path().' of app')
        ->and($twice->codes())->toBe([GenerateErrorCode::InvalidConfig]);
});

it('stops with generate_schema_missing for a file it cannot read', function (): void {
    $root = blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml')]);
    chmod($root->path().'/article.yaml', 0o000);

    try {
        $failed = blueprintFailure([$root]);
    } finally {
        chmod($root->path().'/article.yaml', 0o644);
    }

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaMissing])
        ->and($failed->getMessage())->toContain('schema/article.yaml cannot be read');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('stops with generate_invalid_config when the blueprint schema cannot be read or is not a JSON object', function (string $contents): void {
    $path = SchemaFixtures::scratch().'/blueprint.v1.json';

    if ($contents !== '') {
        SchemaFixtures::write($path, $contents);
    }

    $failed = blueprintFailure([blueprintRoot(['article.yaml' => contractFixture('valid/article.yaml')])], new BlueprintSchemaFile($path));

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->getMessage())->toContain($path);
})->with(['a missing file' => '', 'not JSON' => '{"type": ', 'a JSON list' => '[]']);

it('does not read the blueprint schema when there is no file to validate', function (): void {
    expect(yamlBlueprints(new BlueprintSchemaFile('/nonexistent/blueprint.v1.json'))->read([blueprintRoot()]))->toEqual(new Blueprints([], []));
});

/**
 * A type file with the given fields, each a YAML list item indented as the fields of a type.
 */
function typeWithFields(string ...$fields): string
{
    return "blueprint: 1\nkind: type\ntype_id: 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b\nhandle: note\nlabel: Note\nversion: 1\ncapabilities:\n  history: full\n  stages: none\n  localization: none\nfields:\n".implode('', $fields);
}

it('lets agents see a field whose blueprint does not say so only when it is public or internal (PRD 12.2)', function (Classification $classification, bool $agents): void {
    $root = blueprintRoot(['note.yaml' => typeWithFields("  - handle: body\n    label: Body\n    description: The body of the note.\n    type: text\n    classification: {$classification->value}\n")]);

    expect(yamlBlueprints()->read([$root])->types[0]->fields[0]->agents)->toBe($agents);
})->with([
    'public' => [Classification::Public, true],
    'internal' => [Classification::Internal, true],
    'confidential' => [Classification::Confidential, false],
]);

it('lets agents see a public, internal or confidential field when its blueprint says agents: true', function (Classification $classification): void {
    $root = blueprintRoot(['note.yaml' => typeWithFields("  - handle: body\n    label: Body\n    description: The body of the note.\n    type: text\n    classification: {$classification->value}\n    agents: true\n")]);

    expect(yamlBlueprints()->read([$root])->types[0]->fields[0]->agents)->toBeTrue();
})->with([Classification::Public, Classification::Internal, Classification::Confidential]);

it('hides a field of any classification whose blueprint says agents: false', function (Classification $classification): void {
    $root = blueprintRoot(['note.yaml' => typeWithFields("  - handle: body\n    label: Body\n    description: The body of the note.\n    type: text\n    classification: {$classification->value}\n    agents: false\n")]);

    expect(yamlBlueprints()->read([$root])->types[0]->fields[0]->agents)->toBeFalse();
})->with(Classification::cases());

it('gives the fields of a group the group\'s agents unless they say otherwise', function (string $group, bool $name, bool $role): void {
    $root = blueprintRoot(['note.yaml' => typeWithFields(
        "  - handle: authors\n    label: Authors\n    description: The authors of the note.\n    type: group\n{$group}    fields:\n",
        "      - handle: name\n        label: Name\n        description: The author's name.\n        type: text\n",
        "      - handle: role\n        label: Role\n        description: The author's role.\n        type: text\n        agents: ".($role ? 'true' : 'false')."\n",
    )]);

    $nested = yamlBlueprints()->read([$root])->types[0]->fields[0]->options;

    expect($nested)->toBeInstanceOf(GroupOptions::class);
    assert($nested instanceof GroupOptions);
    expect($nested->fields[0]->agents)->toBe($name)
        ->and($nested->fields[1]->agents)->toBe($role);
})->with([
    'a public group' => ["    classification: public\n", true, false],
    'an internal group' => ["    classification: internal\n", true, false],
    'a confidential group' => ["    classification: confidential\n", false, true],
    'a public group hidden from agents' => ["    classification: public\n    agents: false\n", false, true],
    'an internal group hidden from agents' => ["    classification: internal\n    agents: false\n", false, true],
    'a confidential group agents see' => ["    classification: confidential\n    agents: true\n", true, false],
]);

it('refuses a personal or a sensitive field, whose processing record blueprint v1 cannot declare, also when the installed schema allows it (PRD 12.14)', function (string $classification, bool $lenient, GenerateErrorCode $code): void {
    // The lenient schema stands in for a later cboxdk/cms that allows the classification.
    $schema = $lenient ? laterBlueprintSchema('/$defs/classification/enum', $classification, true) : null;
    $yaml = typeWithFields("  - handle: contact\n    label: Contact\n    description: How to reach the person.\n    type: text\n    classification: {$classification}\n");
    $failed = blueprintFailure([blueprintRoot(['note.yaml' => $yaml])], $schema);

    expect($failed->codes())->toBe([$code])
        ->and(problemPointers($failed->problems, 'schema/note.yaml'))->toBe(['/fields/0/classification']);

    if ($lenient) {
        expect($failed->problems[0]->message)->toContain('needs a newer cboxdk/cms');
    }
})->with([
    'personal' => ['personal', false, GenerateErrorCode::SchemaInvalid],
    'sensitive' => ['sensitive', false, GenerateErrorCode::SchemaInvalid],
    'personal, with a schema that allows it' => ['personal', true, GenerateErrorCode::SchemaUnsupportedVersion],
    'sensitive, with a schema that allows it' => ['sensitive', true, GenerateErrorCode::SchemaUnsupportedVersion],
]);

it('knows exactly the classifications that the installed blueprint schema allows', function (): void {
    $schema = json_decode((string) file_get_contents(new BlueprintSchemaFile()->path()), true, 512, JSON_THROW_ON_ERROR);

    expect(array_map(static fn (Classification $classification): string => $classification->value, Classification::cases()))
        ->toBe(data_get($schema, '$defs.classification.enum'));
});
