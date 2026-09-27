<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Assert;
use RuntimeException;
use stdClass;

/*
 * The blueprint model's own values (PRD 11.2, 11.12). The blueprint schema is the only source of
 * the rules for a file (blueprint decision 5): a value object never refuses what the schema
 * accepts, and the defaults the reader fills in are the schema's own `default` values.
 */

/**
 * The installed blueprint schema, decoded as the validator reads it.
 */
function blueprintSchemaObject(): stdClass
{
    return new BlueprintSchemaFile()->load();
}

/**
 * The subschema at the JSON pointer of the installed blueprint schema.
 */
function schemaAt(string $pointer): stdClass
{
    $node = blueprintSchemaObject();

    foreach (array_slice(explode('/', $pointer), 1) as $segment) {
        $node = match (true) {
            $node instanceof stdClass => $node->{$segment} ?? null,
            is_array($node) => $node[(int) $segment] ?? null,
            default => null,
        };
    }

    if (! $node instanceof stdClass) {
        throw new RuntimeException('The blueprint schema has no subschema at '.$pointer.'.');
    }

    return $node;
}

/**
 * Whether the value passes the schema's definition with the given name.
 */
function passesDefinition(string $definition, string $value): bool
{
    $schema = blueprintSchemaObject();
    $reference = new stdClass;
    $reference->{'$schema'} = $schema->{'$schema'};
    $reference->{'$defs'} = $schema->{'$defs'};
    $reference->{'$ref'} = '#/$defs/'.$definition;

    return new CompliantValidator()->validate($value, $reference)->isValid();
}

/**
 * Every `default` in a decoded JSON Schema, by the JSON pointer of the keyword that holds it.
 *
 * @return array<string, mixed>
 */
function schemaDefaults(mixed $schema, string $pointer = ''): array
{
    $defaults = [];

    foreach (is_object($schema) ? get_object_vars($schema) : (is_array($schema) ? $schema : []) as $key => $value) {
        if ($key === 'default') {
            $defaults[$pointer] = $value;
        } else {
            $defaults = [...$defaults, ...schemaDefaults($value, $pointer.'/'.$key)];
        }
    }

    return $defaults;
}

it('accepts exactly the handles the blueprint schema accepts', function (string $value): void {
    try {
        new Handle($value);
        $accepted = true;
    } catch (GenerationFailed $refused) {
        expect($refused->codes())->toBe([GenerateErrorCode::SchemaInvalid]);
        $accepted = false;
    }

    expect($accepted)->toBe(passesDefinition('handle', $value));
})->with([
    'article', 'reading_minutes', 'a', 'h2', 'x1_y2', str_repeat('a', 63),
    str_repeat('a', 64), 'Article', 'double__underscore', '_leading', 'trailing_', '1st', 'with-dash', '',
    'ext', 'ext_field', 'extra', 'cms_stage', 'cms', 'cmsx', "article\n",
]);

it('takes every type id the blueprint schema accepts as a UUIDv7', function (string $value): void {
    $accepted = true;

    try {
        expect(TypeId::fromString($value)->toString())->toBe($value);
    } catch (InvalidUuid7) {
        $accepted = false;
    }

    expect(passesDefinition('uuid7', $value))->toBe($accepted && strtolower($value) === $value);
})->with([
    '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b', '0192a3b4-c5d6-7e8f-bfff-ffffffffffff', '0192a3b4-c5d6-4e8f-9a0b-1c2d3e4f5a6b',
    '0192a3b4-c5d6-7e8f-ca0b-1c2d3e4f5a6b', '0192a3b4c5d67e8f9a0b1c2d3e4f5a6b', 'not-a-uuid',
]);

it('compares type ids by value', function (): void {
    $id = TypeId::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b');

    expect($id->equals(TypeId::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')))->toBeTrue()
        ->and($id->equals(TypeId::fromString('0192a3b4-c5d6-7e8f-9a0b-aaaaaaaaaaaa')))->toBeFalse();
});

it('fills in exactly the defaults of the blueprint schema', function (): void {
    expect(schemaDefaults(blueprintSchemaObject()))->toEqual([
        '/$defs/capabilities/properties/routable' => Capabilities::DEFAULT_ROUTABLE,
        '/$defs/field/properties/required' => FieldBlueprint::DEFAULT_REQUIRED,
        '/$defs/field/properties/filterable' => FieldBlueprint::DEFAULT_FILTERABLE,
        '/$defs/field/properties/sortable' => FieldBlueprint::DEFAULT_SORTABLE,
        '/$defs/textOptions/properties/max_length' => TextOptions::DEFAULT_MAX_LENGTH,
        '/$defs/textOptions/properties/format' => TextOptions::DEFAULT_FORMAT->value,
        '/$defs/longTextOptions/properties/max_length' => LongTextOptions::DEFAULT_MAX_LENGTH,
        '/$defs/selectOptions/properties/multiple' => SelectOptions::DEFAULT_MULTIPLE,
    ]);
});

it('knows an owner: app, or a module or addon name that is not ext', function (string $value, bool $valid): void {
    try {
        expect(new Owner($value)->value)->toBe($value);
        $accepted = true;
    } catch (GenerationFailed $refused) {
        expect($refused->codes())->toBe([GenerateErrorCode::InvalidConfig]);
        $accepted = false;
    }

    expect($accepted)->toBe($valid);
})->with([
    ['app', true], ['acme', true], ['shop2', true], [str_repeat('a', 20), true],
    ['ext', false], [str_repeat('a', 21), false], ['Acme', false], ['acme_shop', false], ['2shop', false], ['', false],
]);

it('names the owner app and compares owners by value', function (): void {
    expect(Owner::app()->value)->toBe('app')
        ->and(Owner::app()->equals(new Owner('app')))->toBeTrue()
        ->and(Owner::app()->equals(new Owner('acme')))->toBeFalse();
});

it('names a place in a file by its JSON pointer, escaped as RFC 6901 says', function (): void {
    $document = new SourceLocation('schema/article.yaml', '');

    expect($document->describe())->toBe('schema/article.yaml, /')
        ->and($document->below('fields', 2, 'options', 0)->describe())->toBe('schema/article.yaml, /fields/2/options/0')
        ->and($document->below('a/b', 'c~d')->pointer)->toBe('/a~1b/c~0d');
});

it('places a schema root below its base and names its files from the base', function (): void {
    $root = new SchemaRoot(new Owner('acme'), '/srv/app/', 'vendor/acme/shop/schema');

    expect($root->path())->toBe('/srv/app/vendor/acme/shop/schema')
        ->and($root->file('types/product.yaml'))->toBe('vendor/acme/shop/schema/types/product.yaml');
});

it('knows a schema root in vendor/ below its base, where Composer installs packages', function (string $directory, bool $belowVendor): void {
    expect(new SchemaRoot(new Owner('acme'), '/srv/app', $directory)->belowVendor())->toBe($belowVendor);
})->with([
    'an addon root' => ['vendor/acme/shop/schema', true],
    'vendor itself' => ['vendor', true],
    'a file-like name in vendor' => ['vendor/acme.schema', true],
    'the application root' => ['schema', false],
    'a longer first segment' => ['vendors/acme/schema', false],
    'a first segment that starts like it' => ['vendor-schema', false],
    'vendor further down' => ['modules/vendor/schema', false],
    'another case, as a case-insensitive filesystem finds it' => ['Vendor/acme/schema', true],
]);

it('refuses a schema root that is not a directory below an absolute base', function (string $base, string $directory): void {
    try {
        new SchemaRoot(Owner::app(), $base, $directory);
        Assert::fail('The root was accepted.');
    } catch (GenerationFailed $refused) {
        expect($refused->codes())->toBe([GenerateErrorCode::InvalidConfig]);
    }
})->with([
    'a relative base' => ['srv/app', 'schema'],
    'an absolute directory' => ['/srv/app', '/schema'],
    'a parent segment' => ['/srv/app', '../schema'],
    'a trailing slash' => ['/srv/app', 'schema/'],
    'an empty directory' => ['/srv/app', ''],
]);

it('exits cms:generate with 65 for a blueprint that needs a newer cboxdk/cms-generators', function (): void {
    expect(GenerateCommand::exitCode(GenerateErrorCode::SchemaUnsupportedVersion))->toBe(GenerateCommand::EXIT_INVALID_SCHEMA);
});
