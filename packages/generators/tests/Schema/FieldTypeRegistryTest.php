<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\DuplicateFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\TextFieldType;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\InvalidFieldTypeName;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Schema\Fakes\ColourFieldType;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeFieldTypeContributor;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;
use PHPUnit\Framework\Assert;
use stdClass;

/*
 * The one extension point for field types (GUARDRAILS 2.4, blueprint decision 2): the reader
 * resolves the type of every field in the FieldTypeRegistry, and the core registers its own types
 * there through CoreFieldTypes, the same FieldTypeContributor interface as any other contributor.
 * So a registry without the core's contributor reads no core field type, and a type another
 * contributor registers is read as the core's are.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The app's schema root in a scratch directory, with the given files.
 *
 * @param  array<string, string>  $files  contents by path below the root
 */
function registryRoot(array $files): SchemaRoot
{
    $root = new SchemaRoot(Owner::app(), SchemaFixtures::scratch(), 'schema');
    mkdir($root->path(), 0o775, true);

    foreach ($files as $path => $contents) {
        SchemaFixtures::write($root->path().'/'.$path, $contents);
    }

    return $root;
}

function registrySource(FieldTypeRegistry $registry): YamlBlueprintSource
{
    return new YamlBlueprintSource(new BlueprintSchemaFile, new BlueprintDocumentReader($registry), new BlueprintRules);
}

/**
 * The failure of reading the root with the registry.
 */
function registryFailure(FieldTypeRegistry $registry, SchemaRoot $root): GenerationFailed
{
    try {
        registrySource($registry)->read([$root]);
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The reader accepted the files.');
}

/**
 * A type file whose fields have the given handles and types.
 *
 * @param  array<string, string>  $fields  handle to field type
 */
function registryType(array $fields): string
{
    $yaml = <<<'YAML'
        blueprint: 1
        kind: type
        type_id: 0192a3b4-c5d6-7e8f-9a0b-000000000001
        handle: article
        label: Registry fixture
        description: A type for the field type registry.
        version: 1
        capabilities:
          history: full
          stages: none
          localization: none
        fields:

        YAML;

    foreach ($fields as $handle => $type) {
        $yaml .= "  - handle: {$handle}\n    label: Registry field\n    description: A field of the registry fixture.\n    type: {$type}\n    classification: internal\n";
    }

    return $yaml;
}

it('registers the core field types through CoreFieldTypes, sorted by name', function (): void {
    $registry = new FieldTypeRegistry(new CoreFieldTypes);

    expect($registry->names())->toBe(['boolean', 'date', 'datetime', 'decimal', 'group', 'integer', 'long_text', 'rich_text', 'select', 'text'])
        ->and($registry->find('text'))->toBeInstanceOf(TextFieldType::class)
        ->and($registry->find('acme:colour'))->toBeNull()
        ->and(array_map(static fn (FieldType $type): string => $type->name(), new CoreFieldTypes()->fieldTypes()))->toHaveCount(10);
});

it('reads no core field type that the registry lacks, because the core types go through the registry too', function (): void {
    $root = registryRoot(['article.yaml' => registryType(['title' => 'text', 'count' => 'integer'])]);

    $failed = registryFailure(new FieldTypeRegistry(FakeFieldTypeContributor::acme(new ColourFieldType)), $root);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaUnsupportedVersion, GenerateErrorCode::SchemaUnsupportedVersion])
        ->and($failed->problems[1]->message)->toStartWith('schema/article.yaml, /fields/1/type: the value "integer" is not one this cboxdk/cms-generators knows')
        ->and(registryFailure(new FieldTypeRegistry, $root)->problems)->toHaveCount(2)
        ->and(registrySource(new FieldTypeRegistry(new CoreFieldTypes))->read([$root])->types[0]->fields[0]->options)->toEqual(new TextOptions(null, TextOptions::DEFAULT_MAX_LENGTH, TextOptions::DEFAULT_FORMAT));
});

it('reads a field type that another contributor registers as it reads the core\'s, options and unknown keys included', function (): void {
    $registry = new FieldTypeRegistry(new CoreFieldTypes, FakeFieldTypeContributor::acme(new ColourFieldType));
    $root = registryRoot(['article.yaml' => registryType(['title' => 'text', 'colour' => 'acme:colour'])."    options:\n      palette: shop\n"]);

    $colour = registrySource($registry)->read([$root])->types[0]->fields[1];

    expect($colour->options->typeName())->toBe('acme:colour');

    SchemaFixtures::write($root->path().'/article.yaml', registryType(['colour' => 'acme:colour'])."    options:\n      palette: shop\n      shade: dark\n");
    $failed = registryFailure($registry, $root);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaUnsupportedVersion])
        ->and($failed->problems[0]->message)->toStartWith('schema/article.yaml, /fields/0/options/shade: the key "shade" is not one this cboxdk/cms-generators knows');
});

it('refuses a <namespace>:<handle> that no contributor registers with generate_unknown_field_type', function (): void {
    $root = registryRoot(['article.yaml' => registryType(['colour' => 'acme:colour'])]);

    $failed = registryFailure(new FieldTypeRegistry(new CoreFieldTypes), $root);

    expect($failed->codes())->toBe([GenerateErrorCode::UnknownFieldType])
        ->and($failed->problems[0]->message)->toBe('schema/article.yaml, /fields/0/type: no field type contributor registers the field type acme:colour, so it cannot be read. The registered field types are boolean, date, datetime, decimal, group, integer, long_text, rich_text, select, text.');
});

it('refuses two field types with the same name, whichever contributors register them', function (): void {
    $other = FakeFieldTypeContributor::acme(new ColourFieldType);

    expect(static fn (): FieldTypeRegistry => new FieldTypeRegistry(new CoreFieldTypes, FakeFieldTypeContributor::acme(new ColourFieldType), $other))
        ->toThrow(DuplicateFieldType::class, sprintf('The field type "acme:colour" is registered by both %s and %s.', FakeFieldTypeContributor::class, FakeFieldTypeContributor::class))
        ->and(static fn (): FieldTypeRegistry => new FieldTypeRegistry(FakeFieldTypeContributor::acme(new ColourFieldType, new ColourFieldType)))
        ->toThrow(DuplicateFieldType::class, 'The field type "acme:colour" is registered by both');
});

it('registers a contributor\'s field types only as <namespace>:<handle> in its own namespace (PRD 13.1)', function (string $name): void {
    expect(static fn (): FieldTypeRegistry => new FieldTypeRegistry(new CoreFieldTypes, FakeFieldTypeContributor::acme(new ColourFieldType($name))))
        ->toThrow(InvalidFieldTypeName::class, sprintf('The field type "%s" is registered by %s, whose namespace is "acme".', $name, FakeFieldTypeContributor::class));
})->with([
    'a bare name that a later core field type could take' => ['colour'],
    'the name of a core field type' => ['text'],
    'another addon\'s namespace' => ['beta:colour'],
    'the reserved namespace ext' => ['ext:colour'],
    'the reserved namespace app' => ['app:colour'],
    'a namespace that starts with the own' => ['acmex:colour'],
    'no handle after the namespace' => ['acme:'],
    'a handle that is not snake_case' => ['acme:Colour'],
    'a double underscore in the handle' => ['acme:dark__red'],
    'a second colon' => ['acme:colour:dark'],
]);

it('refuses a contributor in the reserved namespace app, which is the application\'s and never an addon\'s (PRD 11.12, 13.1)', function (): void {
    expect(static fn (): FieldTypeRegistry => new FieldTypeRegistry(new CoreFieldTypes, new FakeFieldTypeContributor(Owner::app(), new ColourFieldType('app:colour'))))
        ->toThrow(InvalidFieldTypeName::class, sprintf('%s registers field types in the namespace "app", which is reserved for the application', FakeFieldTypeContributor::class))
        ->and(static fn (): Owner => new Owner('ext'))->toThrow(GenerationFailed::class, 'and not "ext"');
});

it('refuses a contributor without a namespace, because only CoreFieldTypes registers bare names', function (): void {
    expect(static fn (): FieldTypeRegistry => new FieldTypeRegistry(new FakeFieldTypeContributor(null, new ColourFieldType('colour'))))
        ->toThrow(InvalidFieldTypeName::class, sprintf('%s registers field types without a namespace. Only the core\'s %s does;', FakeFieldTypeContributor::class, CoreFieldTypes::class))
        ->and(static fn (): FieldTypeRegistry => new FieldTypeRegistry(new FakeFieldTypeContributor(null)))
        ->toThrow(InvalidFieldTypeName::class, 'registers field types without a namespace');
});

it('registers the core field types without a namespace, each a bare handle', function (): void {
    expect(new CoreFieldTypes()->owner())->toBeNull();

    foreach (new CoreFieldTypes()->fieldTypes() as $type) {
        expect($type->name())->toMatch(Handle::PATTERN);
    }
});

it('registers field types of several namespaces beside the core\'s', function (): void {
    $registry = new FieldTypeRegistry(new CoreFieldTypes, FakeFieldTypeContributor::acme(new ColourFieldType, new ColourFieldType('acme:dark_colour')), new FakeFieldTypeContributor(new Owner('beta2'), new ColourFieldType('beta2:colour')));

    expect($registry->names())->toBe(['acme:colour', 'acme:dark_colour', 'beta2:colour', 'boolean', 'date', 'datetime', 'decimal', 'group', 'integer', 'long_text', 'rich_text', 'select', 'text']);
});

it('gives each core field type the option keys of its options in the installed blueprint schema', function (): void {
    $schema = new BlueprintSchemaFile()->load();
    $definitions = $schema->{'$defs'} ?? null;
    $field = $definitions instanceof stdClass ? $definitions->field ?? null : null;

    if (! $definitions instanceof stdClass || ! $field instanceof stdClass || ! is_array($field->allOf ?? null)) {
        throw new LogicException('The blueprint schema has no $defs/field/allOf.');
    }

    /** @var array<string, list<string>> $schemaKeys the option keys of each core field type, by name */
    $schemaKeys = [];

    foreach ($field->allOf as $rule) {
        $type = data_get($rule, 'if.properties.type.const');
        $reference = data_get($rule, 'then.$ref');

        if (is_string($type) && is_string($reference)) {
            $options = $definitions->{substr($reference, strlen('#/$defs/'))} ?? null;
            $properties = $options instanceof stdClass ? $options->properties ?? null : null;
            $schemaKeys[$type] = $properties instanceof stdClass ? array_map(strval(...), array_keys(get_object_vars($properties))) : [];
        }
    }

    foreach (new CoreFieldTypes()->fieldTypes() as $type) {
        $keys = $type->optionKeys();
        $expected = $schemaKeys[$type->name()] ?? [];
        sort($keys);
        sort($expected);

        expect($keys)->toBe($expected, $type->name());
    }
});
