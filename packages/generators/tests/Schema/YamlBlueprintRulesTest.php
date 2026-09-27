<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Schema\Fakes\ColourFieldType;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeFieldTypeContributor;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;
use PHPUnit\Framework\Assert;

/*
 * The rules of the blueprint schema v1 that JSON Schema cannot express (blueprint proposal, "Regler
 * som JSON Schema ikke kan udtrykke"), on schema roots of real files read by the blueprint source
 * in the container. Each rule fails with its own code at the file and JSON pointer that break it.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const RULES_ARTICLE_ID = '0192a3b4-c5d6-7e8f-9a0b-000000000001';

const RULES_PAGE_ID = '0192a3b4-c5d6-7e8f-9a0b-000000000002';

const RULES_PRODUCT_ID = '0192a3b4-c5d6-7e8f-9a0b-000000000003';

/**
 * A root of the owner below the base, with the given files.
 *
 * @param  array<string, string>  $files  contents by path below the root
 */
function rulesRoot(string $base, array $files, string $owner = 'app', string $directory = 'schema'): SchemaRoot
{
    $root = new SchemaRoot(new Owner($owner), $base, $directory);
    mkdir($root->path(), 0o775, true);

    foreach ($files as $path => $contents) {
        SchemaFixtures::write($root->path().'/'.$path, $contents);
    }

    return $root;
}

/**
 * A type file with the given top-level fields.
 */
function rulesType(string $typeId, string $handle, string ...$fields): string
{
    return <<<YAML
        blueprint: 1
        kind: type
        type_id: {$typeId}
        handle: {$handle}
        label: Rules fixture
        description: A type for the rules of the reader.
        version: 1
        capabilities:
          history: full
          stages: none
          localization: none
        fields:

        YAML.implode('', $fields);
}

/**
 * An extension file with the given top-level fields.
 */
function rulesExtension(string $extends, string ...$fields): string
{
    return rulesVersionedExtension(1, $extends, ...$fields);
}

/**
 * An extension file of the given version with the given top-level fields.
 */
function rulesVersionedExtension(int $version, string $extends, string ...$fields): string
{
    return <<<YAML
        blueprint: 1
        kind: extension
        extends: {$extends}
        version: {$version}
        fields:

        YAML.implode('', $fields);
}

/**
 * A top-level field of the type with its options, each option a line such as "min: 1".
 */
function rulesField(string $handle, string $type, string ...$options): string
{
    $lines = [
        '  - handle: '.$handle,
        '    label: Rules field',
        '    description: A field for the rules of the reader.',
        '    type: '.$type,
        '    classification: internal',
        ...array_map(static fn (string $option): string => '    '.$option, $options),
    ];

    return implode("\n", $lines)."\n";
}

/**
 * A group field whose fields are text fields with the handles given.
 */
function rulesGroup(string $handle, string ...$fields): string
{
    $lines = [
        '  - handle: '.$handle,
        '    label: Rules group',
        '    description: A group for the rules of the reader.',
        '    type: group',
        '    classification: internal',
        '    fields:',
    ];

    foreach ($fields as $field) {
        $lines[] = '      - handle: '.$field;
        $lines[] = '        label: Rules field';
        $lines[] = '        description: A field in the group.';
        $lines[] = '        type: text';
    }

    return implode("\n", $lines)."\n";
}

/**
 * The files of a dataset, as rulesRoot() takes them.
 *
 * @param  array<mixed>  $files
 * @return array<string, string>
 */
function rulesFiles(array $files): array
{
    $typed = [];

    foreach ($files as $path => $contents) {
        if (! is_string($path) || ! is_string($contents)) {
            throw new LogicException('The files of a dataset are contents by path.');
        }

        $typed[$path] = $contents;
    }

    return $typed;
}

/**
 * @param  list<SchemaRoot>  $roots
 */
function rulesRead(array $roots): Blueprints
{
    return app(BlueprintSource::class)->read($roots);
}

/**
 * @param  list<SchemaRoot>  $roots
 */
function rulesFailure(array $roots): GenerationFailed
{
    try {
        rulesRead($roots);
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The reader accepted the files.');
}

/**
 * The problem of the only failure, which must be of the code and start at the file and pointer.
 */
function rulesProblem(GenerationFailed $failed, GenerateErrorCode $code, string $at): GenerationProblem
{
    expect($failed->codes())->toBe([$code])
        ->and($failed->problems[0]->message)->toStartWith($at.': ');

    return $failed->problems[0];
}

it('binds the field type registry with the core\'s field types, registered through CoreFieldTypes', function (): void {
    expect(app(FieldTypeRegistry::class)->names())->toBe(new FieldTypeRegistry(new CoreFieldTypes)->names())
        ->and(app(FieldTypeRegistry::class))->toBe(app(FieldTypeRegistry::class))
        ->and(app(FieldTypeRegistry::class)->find('acme:colour'))->toBeNull();
});

it('rejects two types with the same type_id with generate_duplicate_type_id, in one root and across owners', function (): void {
    $base = SchemaFixtures::scratch();
    $app = rulesRoot($base, [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text')),
        'page.yaml' => rulesType(RULES_ARTICLE_ID, 'page', rulesField('title', 'text')),
    ]);
    $acme = rulesRoot($base, ['product.yaml' => rulesType(RULES_ARTICLE_ID, 'product', rulesField('name', 'text'))], 'acme', 'vendor/acme/shop/schema');

    $failed = rulesFailure([$app, $acme]);

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateTypeId, GenerateErrorCode::DuplicateTypeId])
        ->and($failed->problems[0]->message)->toBe('schema/page.yaml, /type_id: the type_id '.RULES_ARTICLE_ID.' is already the type_id of the type in schema/article.yaml. Every type needs its own type_id: give one of them a new UUIDv7.')
        ->and($failed->problems[1]->message)->toStartWith('vendor/acme/shop/schema/product.yaml, /type_id: ')
        ->and($failed->problems[1]->message)->toContain('schema/article.yaml');
});

it('rejects two types of one owner with the same handle with generate_duplicate_type_handle', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text')),
        'blog/article.yaml' => rulesType(RULES_PAGE_ID, 'article', rulesField('title', 'text')),
    ]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::DuplicateTypeHandle, 'schema/blog/article.yaml, /handle');

    expect($problem->message)->toContain('already the handle of the type in schema/article.yaml');
});

it('reads the same type handle under two owners, since a handle belongs to one type of each owner', function (): void {
    $base = SchemaFixtures::scratch();
    $app = rulesRoot($base, ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text'))]);
    $acme = rulesRoot($base, ['article.yaml' => rulesType(RULES_PAGE_ID, 'article', rulesField('title', 'text'))], 'acme', 'vendor/acme/shop/schema');

    $types = rulesRead([$app, $acme])->types;

    expect(array_map(static fn (TypeBlueprint $type): string => $type->owner->value.':'.$type->handle->value, $types))->toBe(['app:article', 'acme:article']);
});

it('rejects two fields with the same handle in one namespace with generate_duplicate_field_handle', function (array $files, string $at, string $first): void {
    $base = SchemaFixtures::scratch();
    $roots = [rulesRoot($base, ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'))], 'acme', 'vendor/acme/shop/schema')];

    if ($files !== []) {
        $roots[] = rulesRoot($base, rulesFiles($files));
    }

    $problem = rulesProblem(rulesFailure($roots), GenerateErrorCode::DuplicateFieldHandle, $at);

    expect($problem->message)->toContain('already the handle of the field at '.$first);
})->with([
    'the fields of a type' => [
        ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text'), rulesField('body', 'long_text'), rulesField('title', 'integer'))],
        'schema/article.yaml, /fields/2/handle',
        'schema/article.yaml, /fields/0',
    ],
    'the fields of one extension' => [
        ['tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('tax_code', 'text'), rulesField('tax_code', 'text'))],
        'schema/tax.yaml, /fields/1/handle',
        'schema/tax.yaml, /fields/0',
    ],
    'the fields one owner adds to one type in two files' => [
        ['a/tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('tax_code', 'text')), 'b/tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('note', 'text'), rulesField('tax_code', 'text'))],
        'schema/b/tax.yaml, /fields/1/handle',
        'schema/a/tax.yaml, /fields/0',
    ],
    'the fields of a group' => [
        ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text'), rulesGroup('credits', 'name', 'role', 'name'))],
        'schema/article.yaml, /fields/1/fields/2/handle',
        'schema/article.yaml, /fields/1/fields/0',
    ],
]);

it('reads the same field handle in different namespaces', function (): void {
    $base = SchemaFixtures::scratch();
    $acme = rulesRoot($base, [
        'product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'), rulesGroup('variants', 'name')),
        'article_name.yaml' => rulesExtension(RULES_ARTICLE_ID, rulesField('name', 'text')),
    ], 'acme', 'vendor/acme/shop/schema');
    $blog = rulesRoot($base, ['product_name.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('name', 'text'))], 'blog', 'vendor/acme/blog/schema');
    $app = rulesRoot($base, [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('name', 'text'), rulesGroup('credits', 'name'), rulesGroup('sources', 'name')),
        'product_name.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('name', 'text')),
    ]);

    $blueprints = rulesRead([$app, $acme, $blog]);

    expect($blueprints->types)->toHaveCount(2)
        ->and($blueprints->extensions)->toHaveCount(3);
});

it('rejects two options of a select field with the same value with generate_duplicate_select_value', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField(
        'section',
        'select',
        'options:',
        '  - { value: news, label: News }',
        '  - { value: sport, label: Sport }',
        '  - { value: news, label: More news }',
    ))]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::DuplicateSelectValue, 'schema/article.yaml, /fields/0/options/2/value');

    expect($problem->message)->toContain('already the value of the option at schema/article.yaml, /fields/0/options/0/value');
});

it('rejects an extension of a type_id that no file defines with generate_unknown_extends_target', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text')),
        'tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
    ]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::UnknownExtendsTarget, 'schema/tax.yaml, /extends');

    expect($problem->message)->toContain('type_id '.RULES_PRODUCT_ID);
});

it('rejects an extension of a type of its own owner with generate_extension_of_own_type', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), [
        'product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text')),
        'tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
    ]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::ExtensionOfOwnType, 'schema/tax.yaml, /extends');

    expect($problem->message)->toContain('the type product in schema/product.yaml, which app owns')
        ->and($problem->message)->toContain('add the fields to schema/product.yaml');
});

it('rejects extension files of one owner for one type with different versions with generate_extension_version_mismatch', function (): void {
    $base = SchemaFixtures::scratch();
    $acme = rulesRoot($base, ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'))], 'acme', 'vendor/acme/shop/schema');
    $app = rulesRoot($base, [
        'a.yaml' => rulesVersionedExtension(1, RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
        'b.yaml' => rulesVersionedExtension(3, RULES_PRODUCT_ID, rulesField('note', 'text')),
    ]);

    $problem = rulesProblem(rulesFailure([$acme, $app]), GenerateErrorCode::ExtensionVersionMismatch, 'schema/b.yaml, /version');

    expect($problem->message)->toContain('the version 3 differs from the version 1 of schema/a.yaml')
        ->and($problem->message)->toContain('the fields app adds to the type '.RULES_PRODUCT_ID);
});

it('reports each extension file whose version differs from the first file of its owner for the type', function (): void {
    $base = SchemaFixtures::scratch();
    $acme = rulesRoot($base, ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'))], 'acme', 'vendor/acme/shop/schema');
    $app = rulesRoot($base, [
        'a.yaml' => rulesVersionedExtension(2, RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
        'b.yaml' => rulesVersionedExtension(2, RULES_PRODUCT_ID, rulesField('note', 'text')),
        'c.yaml' => rulesVersionedExtension(1, RULES_PRODUCT_ID, rulesField('colour', 'text')),
        'd.yaml' => rulesVersionedExtension(3, RULES_PRODUCT_ID, rulesField('size', 'text')),
    ]);

    $failed = rulesFailure([$acme, $app]);

    expect($failed->codes())->toBe([GenerateErrorCode::ExtensionVersionMismatch, GenerateErrorCode::ExtensionVersionMismatch])
        ->and($failed->problems[0]->message)->toStartWith('schema/c.yaml, /version: the version 1 differs from the version 2 of schema/a.yaml')
        ->and($failed->problems[1]->message)->toStartWith('schema/d.yaml, /version: the version 3 differs from the version 2 of schema/a.yaml');
});

it('reads extension files of one owner for one type with the same version, and other owners\' extensions of the type at other versions', function (): void {
    $base = SchemaFixtures::scratch();
    $acme = rulesRoot($base, [
        'product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text')),
        'article_tax.yaml' => rulesVersionedExtension(5, RULES_ARTICLE_ID, rulesField('tax_code', 'text')),
    ], 'acme', 'vendor/acme/shop/schema');
    $blog = rulesRoot($base, ['product_teaser.yaml' => rulesVersionedExtension(1, RULES_PRODUCT_ID, rulesField('teaser', 'text'))], 'blog', 'vendor/acme/blog/schema');
    $app = rulesRoot($base, [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text')),
        'a.yaml' => rulesVersionedExtension(3, RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
        'b.yaml' => rulesVersionedExtension(3, RULES_PRODUCT_ID, rulesField('note', 'text')),
    ]);

    expect(rulesRead([$acme, $blog, $app])->extensions)->toHaveCount(4);
});

it('accepts an extension of a type of another owner, also when the extender owns a type with the same handle', function (): void {
    $base = SchemaFixtures::scratch();
    $shop = rulesRoot($base, ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'))], 'shop', 'vendor/acme/shop/schema');
    $app = rulesRoot($base, [
        'product.yaml' => rulesType(RULES_ARTICLE_ID, 'product', rulesField('name', 'text')),
        'tax.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField('tax_code', 'text')),
    ]);

    expect(rulesRead([$shop, $app])->extensions)->toHaveCount(1);
});

it('rejects an extension field whose column ext__<namespace>__<handle> is over 63 bytes with generate_column_name_too_long', function (string $owner, int $length, bool $fits): void {
    $base = SchemaFixtures::scratch();
    $handle = 'a'.str_repeat('b', $length - 1);
    $product = rulesRoot($base, ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'))], 'shop', 'vendor/acme/shop/schema');
    $extender = rulesRoot($base, ['long.yaml' => rulesExtension(RULES_PRODUCT_ID, rulesField($handle, 'text'))], $owner);
    $column = 'ext__'.$owner.'__'.$handle;

    if ($fits) {
        expect(strlen($column))->toBe(63)
            ->and(rulesRead([$product, $extender])->extensions)->toHaveCount(1);

        return;
    }

    $problem = rulesProblem(rulesFailure([$product, $extender]), GenerateErrorCode::ColumnNameTooLong, 'schema/long.yaml, /fields/0/handle');

    expect($problem->message)->toBe(sprintf('schema/long.yaml, /fields/0/handle: the column name %s has 64 bytes, and Postgres allows at most 63. Shorten the handle by 1 characters.', $column));
})->with([
    'app, 63 bytes' => ['app', 53, true],
    'app, 64 bytes' => ['app', 54, false],
    'a long namespace, 63 bytes' => ['acmeanalyticssuite', 38, true],
    'a long namespace, 64 bytes' => ['acmeanalyticssuite', 39, false],
]);

it('reads a type field whose handle has 63 bytes, since its column is the handle', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('a'.str_repeat('b', 62), 'text'))]);

    expect(rulesRead([$app])->types[0]->fields[0]->handle->value)->toHaveLength(63);
});

it('rejects a min greater than the max with generate_min_above_max', function (string $type, string $min, string $max): void {
    $options = $type === 'decimal' ? ['precision: 38', 'scale: 30'] : [];
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('value', $type, ...$options, ...['min: '.$min, 'max: '.$max]))]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::MinAboveMax, 'schema/article.yaml, /fields/0/min');

    expect($problem->message)->toContain(sprintf('min %s is greater than max %s', trim($min, "'"), trim($max, "'")));
})->with([
    'integer' => ['integer', '10', '9'],
    'decimal' => ['decimal', "'10.5'", "'9.75'"],
    'decimal beyond a float' => ['decimal', "'1234567890123456.000000000000000000002'", "'1234567890123456.000000000000000000001'"],
    'negative decimal' => ['decimal', "'-1.5'", "'-1.75'"],
    'date' => ['date', "'2026-01-02'", "'2026-01-01'"],
    'datetime' => ['datetime', "'2026-01-01T00:00:00Z'", "'2025-12-31T23:59:59Z'"],
    'datetime across offsets' => ['datetime', "'2026-01-01T00:30:00+00:00'", "'2026-01-01T01:00:00+01:00'"],
    'datetime by a digit of the fraction beyond microseconds' => ['datetime', "'2026-01-01T00:00:00.0000002Z'", "'2026-01-01T00:00:00.0000001Z'"],
]);

it('reads a min equal to or below the max', function (string $type, string $min, string $max): void {
    $options = $type === 'decimal' ? ['precision: 38', 'scale: 30'] : [];
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('value', $type, ...$options, ...['min: '.$min, 'max: '.$max]))]);

    expect(rulesRead([$app])->types)->toHaveCount(1);
})->with([
    'integer' => ['integer', '-5', '-5'],
    'decimal with trailing zeros' => ['decimal', "'1.50'", "'1.5'"],
    'decimal with a longer integer part' => ['decimal', "'9.999999999999999999'", "'10'"],
    'negative and positive zero' => ['decimal', "'-0.0'", "'0'"],
    'date' => ['date', "'2025-12-31'", "'2026-01-01'"],
    'datetime across offsets' => ['datetime', "'2026-01-01T01:00:00+02:00'", "'2025-12-31T23:30:00Z'"],
]);

it('rejects a min_length greater than the max_length with generate_min_length_above_max_length, the default max_length too', function (string $type, string ...$options): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('value', $type, ...$options))]);

    rulesProblem(rulesFailure([$app]), GenerateErrorCode::MinLengthAboveMaxLength, 'schema/article.yaml, /fields/0/min_length');
})->with([
    'text' => ['text', 'min_length: 20', 'max_length: 10'],
    'text over its default max_length' => ['text', 'min_length: 256'],
    'long_text' => ['long_text', 'min_length: 20', 'max_length: 10'],
    'long_text over its default max_length' => ['long_text', 'min_length: 10001'],
]);

it('rejects a min_items greater than the max_items with generate_min_items_above_max_items', function (string $field, string $at): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', $field)]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::MinItemsAboveMaxItems, $at);

    expect($problem->message)->toContain('min_items 3 is greater than max_items 2');
})->with([
    'a select field' => [
        rulesField('sections', 'select', 'multiple: true', 'min_items: 3', 'max_items: 2', 'options:', '  - { value: news, label: News }', '  - { value: sport, label: Sport }', '  - { value: culture, label: Culture }'),
        'schema/article.yaml, /fields/0/min_items',
    ],
    'a repeated group' => [
        rulesGroup('credits', 'name')."    repeat:\n      min_items: 3\n      max_items: 2\n",
        'schema/article.yaml, /fields/0/repeat/min_items',
    ],
]);

it('rejects a scale greater than the precision with generate_scale_above_precision', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('rating', 'decimal', 'precision: 3', 'scale: 4'), rulesField('price', 'decimal', 'precision: 4', 'scale: 4'))]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::ScaleAbovePrecision, 'schema/article.yaml, /fields/0/scale');

    expect($problem->message)->toContain('scale 4 is greater than precision 3');
});

it('rejects a field type that no contributor registers with generate_unknown_field_type', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'), rulesField('colour', 'acme:colour', 'options: { palette: shop }'))]);

    $problem = rulesProblem(rulesFailure([$app]), GenerateErrorCode::UnknownFieldType, 'schema/product.yaml, /fields/1/type');

    expect($problem->message)->toContain('no field type contributor registers the field type acme:colour')
        ->and($problem->message)->toContain('The registered field types are boolean, date, datetime, decimal, group, integer, long_text, rich_text, select, text.');
});

it('reads a field type that another contributor registers, and only that one', function (): void {
    app()->instance(FieldTypeRegistry::class, new FieldTypeRegistry(new CoreFieldTypes, FakeFieldTypeContributor::acme(new ColourFieldType)));
    $app = rulesRoot(SchemaFixtures::scratch(), [
        'product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('name', 'text'), rulesField('colour', 'acme:colour', 'options: { palette: shop }')),
    ]);

    expect(rulesRead([$app])->types[0]->fields[1]->options->typeName())->toBe('acme:colour');

    SchemaFixtures::write($app->path().'/article.yaml', rulesType(RULES_ARTICLE_ID, 'article', rulesField('rating', 'acme:stars')));

    rulesProblem(rulesFailure([$app]), GenerateErrorCode::UnknownFieldType, 'schema/article.yaml, /fields/0/type');
});

it('checks the field type of a field inside a group', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), ['article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text'), implode("\n", [
        '  - handle: credits',
        '    label: Credits',
        '    description: The people who made the article.',
        '    type: group',
        '    classification: personal',
        '    fields:',
        '      - handle: portrait',
        '        label: Portrait',
        '        description: A portrait of the person.',
        '        type: acme:image',
    ])."\n")]);

    rulesProblem(rulesFailure([$app]), GenerateErrorCode::UnknownFieldType, 'schema/article.yaml, /fields/1/fields/0/type');
});

it('reports every rule a set of roots breaks in one run, beside the problems of files it could not read', function (): void {
    $app = rulesRoot(SchemaFixtures::scratch(), [
        'article.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('title', 'text', 'min_length: 30', 'max_length: 20'), rulesField('rating', 'decimal', 'precision: 2', 'scale: 3'), rulesField('title', 'text')),
        'broken.yaml' => rulesType(RULES_PAGE_ID, 'Page', rulesField('title', 'text')),
        'page.yaml' => rulesType(RULES_ARTICLE_ID, 'article', rulesField('count', 'integer', 'min: 2', 'max: 1')),
        'product.yaml' => rulesType(RULES_PRODUCT_ID, 'product', rulesField('rating', 'acme:stars')),
    ]);

    $failed = rulesFailure([$app]);

    expect(array_map(static fn (GenerationProblem $problem): string => $problem->code->value.' '.explode(': ', $problem->message, 2)[0], $failed->problems))->toBe([
        'generate_duplicate_field_handle schema/article.yaml, /fields/2/handle',
        'generate_duplicate_type_handle schema/page.yaml, /handle',
        'generate_duplicate_type_id schema/page.yaml, /type_id',
        'generate_min_above_max schema/page.yaml, /fields/0/min',
        'generate_min_length_above_max_length schema/article.yaml, /fields/0/min_length',
        'generate_scale_above_precision schema/article.yaml, /fields/1/scale',
        'generate_schema_invalid schema/broken.yaml, /handle',
        'generate_unknown_field_type schema/product.yaml, /fields/0/type',
    ]);
});
