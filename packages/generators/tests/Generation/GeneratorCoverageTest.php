<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Contracts\FieldTypes\BooleanShape;
use Cbox\Cms\Contracts\FieldTypes\DateShape;
use Cbox\Cms\Contracts\FieldTypes\DatetimeShape;
use Cbox\Cms\Contracts\FieldTypes\DecimalShape;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Cbox\Cms\Contracts\FieldTypes\LongTextShape;
use Cbox\Cms\Contracts\FieldTypes\SelectChoice;
use Cbox\Cms\Contracts\FieldTypes\SelectShape;
use Cbox\Cms\Contracts\FieldTypes\TextShape;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeTableMigrations;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\ShapeOptions;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;
use stdClass;

/*
 * The generator coverage of the blueprint schema v1 (GUARDRAILS 11, blueprint decision 2). Every
 * core field type and every kind in the installed blueprint.v1.json has a mapping in every
 * generator, and every mapping names a field type or a kind that the schema has.
 * Version 1 grows by additions, so a new field type or kind in cboxdk/cms fails here
 * until the generators write it. The core's contributor to the field type registry registers
 * exactly the core field types of the schema, so the reader can read every one of them.
 *
 * An addon's field type is written by every generator as the core field type its shape takes the
 * form of (PRD 11.12, 13.3), so every base of Cbox\Cms\Contracts\FieldTypes\FieldBase is a core
 * field type that every generator maps, and a shape of each base gives the options of that core
 * field type.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The mappings of each generator, by what they map.
 *
 * @return array<string, array{fieldTypes: array<string, string>, kinds: array<string, string>}>
 */
function generatorMappings(): array
{
    return [
        PhpRecordDtos::class => ['fieldTypes' => PhpRecordDtos::FIELD_TYPES, 'kinds' => PhpRecordDtos::KINDS],
        PhpTypeHandleEnum::class => ['fieldTypes' => PhpTypeHandleEnum::FIELD_TYPES, 'kinds' => PhpTypeHandleEnum::KINDS],
        PhpRecords::class => ['fieldTypes' => PhpRecords::FIELD_TYPES, 'kinds' => PhpRecords::KINDS],
        PhpTypeCatalog::class => ['fieldTypes' => PhpTypeCatalog::FIELD_TYPES, 'kinds' => PhpTypeCatalog::KINDS],
        PhpTypeQueries::class => ['fieldTypes' => PhpTypeQueries::FIELD_TYPES, 'kinds' => PhpTypeQueries::KINDS],
        PhpTypeValidators::class => ['fieldTypes' => PhpTypeValidators::FIELD_TYPES, 'kinds' => PhpTypeValidators::KINDS],
        TypeScriptTypeHandles::class => ['fieldTypes' => TypeScriptTypeHandles::FIELD_TYPES, 'kinds' => TypeScriptTypeHandles::KINDS],
        TypeScriptContracts::class => ['fieldTypes' => TypeScriptContracts::FIELD_TYPES, 'kinds' => TypeScriptContracts::KINDS],
        TypeTableMigrations::class => ['fieldTypes' => TypeTableMigrations::FIELD_TYPES, 'kinds' => TypeTableMigrations::KINDS],
    ];
}

/** The path of the core field types in blueprint.v1.json: the first choice of a field's `type`. */
const FIELD_TYPES_ENUM = ['$defs', 'field', 'properties', 'type', 'anyOf', 0];

/** The path of the kinds in blueprint.v1.json. */
const KINDS_ENUM = ['properties', 'kind'];

/**
 * The object at a path of object keys and list indexes in the schema, which holds an `enum`.
 *
 * @param  list<string|int>  $path
 */
function enumHolder(stdClass $schema, array $path): stdClass
{
    $value = $schema;

    foreach ($path as $segment) {
        $value = match (true) {
            is_int($segment) && is_array($value) => $value[$segment] ?? null,
            is_string($segment) && $value instanceof stdClass => $value->{$segment} ?? null,
            default => null,
        };
    }

    if (! $value instanceof stdClass) {
        throw new LogicException(sprintf('The blueprint schema has no object at %s.', implode('/', $path)));
    }

    return $value;
}

/**
 * The enum of strings at a path in the schema.
 *
 * @param  list<string|int>  $path
 * @return list<string>
 */
function schemaEnum(stdClass $schema, array $path): array
{
    $enum = enumHolder($schema, $path)->enum ?? null;

    if (! is_array($enum) || ! array_is_list($enum) || array_filter($enum, is_string(...)) !== $enum) {
        throw new LogicException(sprintf('The blueprint schema has no enum of strings at %s.', implode('/', $path)));
    }

    return $enum;
}

/**
 * The core field types and the kinds of a blueprint schema.
 *
 * @return array{fieldTypes: list<string>, kinds: list<string>}
 */
function schemaValues(stdClass $schema): array
{
    return [
        'fieldTypes' => schemaEnum($schema, FIELD_TYPES_ENUM),
        'kinds' => schemaEnum($schema, KINDS_ENUM),
    ];
}

/**
 * What the generators miss of a blueprint schema, and what they map that it does not have.
 *
 * @param  array<string, array{fieldTypes: array<string, string>, kinds: array<string, string>}>  $generators
 * @return list<string>
 */
function coverageProblems(stdClass $schema, array $generators): array
{
    $problems = [];

    foreach (schemaValues($schema) as $what => $values) {
        foreach ($generators as $generator => $mappings) {
            $mapped = array_keys($mappings[$what]);

            foreach (array_diff($values, $mapped) as $missing) {
                $problems[] = sprintf('%s has no mapping for the %s "%s".', $generator, $what === 'kinds' ? 'kind' : 'field type', $missing);
            }

            foreach (array_diff($mapped, $values) as $extra) {
                $problems[] = sprintf('%s maps the %s "%s", which the blueprint schema does not have.', $generator, $what === 'kinds' ? 'kind' : 'field type', $extra);
            }
        }
    }

    return $problems;
}

/**
 * A copy of the installed blueprint schema, read back from a scratch file after a change.
 *
 * @param  callable(stdClass): void  $change
 */
function changedSchemaCopy(callable $change): stdClass
{
    $copy = json_decode(json_encode(new BlueprintSchemaFile()->load(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    if (! $copy instanceof stdClass) {
        throw new LogicException('The blueprint schema is not an object.');
    }

    $change($copy);
    $path = SchemaFixtures::scratch().'/blueprint.v1.json';
    file_put_contents($path, json_encode($copy, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return new BlueprintSchemaFile($path)->load();
}

it('maps every core field type and every kind of the installed blueprint schema in both generators', function (): void {
    $schema = new BlueprintSchemaFile()->load();

    expect(schemaValues($schema)['fieldTypes'])->toContain('text', 'rich_text', 'group')
        ->and(schemaValues($schema)['kinds'])->toBe(['type', 'extension'])
        ->and(coverageProblems($schema, generatorMappings()))->toBe([]);
});

it('fails when the schema gains a field type or a kind that a generator does not map', function (): void {
    $schema = changedSchemaCopy(static function (stdClass $schema): void {
        enumHolder($schema, FIELD_TYPES_ENUM)->enum = [...schemaEnum($schema, FIELD_TYPES_ENUM), 'relation'];
        enumHolder($schema, KINDS_ENUM)->enum = [...schemaEnum($schema, KINDS_ENUM), 'fieldset'];
    });

    expect(coverageProblems($schema, generatorMappings()))->toBe([
        PhpRecordDtos::class.' has no mapping for the field type "relation".',
        PhpTypeHandleEnum::class.' has no mapping for the field type "relation".',
        PhpRecords::class.' has no mapping for the field type "relation".',
        PhpTypeCatalog::class.' has no mapping for the field type "relation".',
        PhpTypeQueries::class.' has no mapping for the field type "relation".',
        PhpTypeValidators::class.' has no mapping for the field type "relation".',
        TypeScriptTypeHandles::class.' has no mapping for the field type "relation".',
        TypeScriptContracts::class.' has no mapping for the field type "relation".',
        TypeTableMigrations::class.' has no mapping for the field type "relation".',
        PhpRecordDtos::class.' has no mapping for the kind "fieldset".',
        PhpTypeHandleEnum::class.' has no mapping for the kind "fieldset".',
        PhpRecords::class.' has no mapping for the kind "fieldset".',
        PhpTypeCatalog::class.' has no mapping for the kind "fieldset".',
        PhpTypeQueries::class.' has no mapping for the kind "fieldset".',
        PhpTypeValidators::class.' has no mapping for the kind "fieldset".',
        TypeScriptTypeHandles::class.' has no mapping for the kind "fieldset".',
        TypeScriptContracts::class.' has no mapping for the kind "fieldset".',
        TypeTableMigrations::class.' has no mapping for the kind "fieldset".',
    ]);
});

it('fails when a generator maps a field type or a kind that the schema does not have', function (): void {
    $generators = [
        PhpTypeHandleEnum::class => ['fieldTypes' => [...PhpTypeHandleEnum::FIELD_TYPES, 'markdown' => 'markdown'], 'kinds' => PhpTypeHandleEnum::KINDS],
        TypeScriptTypeHandles::class => ['fieldTypes' => TypeScriptTypeHandles::FIELD_TYPES, 'kinds' => [...TypeScriptTypeHandles::KINDS, 'block' => 'a block type']],
    ];

    expect(coverageProblems(new BlueprintSchemaFile()->load(), $generators))->toBe([
        PhpTypeHandleEnum::class.' maps the field type "markdown", which the blueprint schema does not have.',
        TypeScriptTypeHandles::class.' maps the kind "block", which the blueprint schema does not have.',
    ]);
});

it('registers exactly the core field types of the installed blueprint schema through CoreFieldTypes', function (): void {
    $schemaTypes = schemaValues(new BlueprintSchemaFile()->load())['fieldTypes'];
    sort($schemaTypes);

    expect(new FieldTypeRegistry(new CoreFieldTypes)->names())->toBe($schemaTypes);
});

/**
 * The bases an addon's field type can take that a generator does not map.
 *
 * @param  array<string, array{fieldTypes: array<string, string>, kinds: array<string, string>}>  $generators
 * @return list<string>
 */
function contributedCoverageProblems(array $generators): array
{
    $problems = [];

    foreach (FieldBase::cases() as $base) {
        foreach ($generators as $generator => $mappings) {
            if (! array_key_exists($base->value, $mappings['fieldTypes'])) {
                $problems[] = sprintf('%s has no mapping for the base "%s" of an addon\'s field type.', $generator, $base->value);
            }
        }
    }

    return $problems;
}

/**
 * A shape of each base an addon's field type can take.
 *
 * @return array<string, FieldShape>
 */
function shapeOfEveryBase(): array
{
    $shapes = [
        new TextShape(maxLength: 20),
        new LongTextShape,
        new IntegerShape(1, 5),
        new DecimalShape(5, 2, '0', '100'),
        new BooleanShape,
        new DateShape('2026-01-01'),
        new DatetimeShape(max: '2030-01-01T00:00:00Z'),
        new SelectShape([new SelectChoice('small', 'Small'), new SelectChoice('large', 'Large')]),
    ];
    $byBase = [];

    foreach ($shapes as $shape) {
        $byBase[$shape->base()->value] = $shape;
    }

    return $byBase;
}

it('writes a field of every base an addon\'s field type can take in every generator, as the core field type of the base', function (): void {
    $core = schemaValues(new BlueprintSchemaFile()->load())['fieldTypes'];
    $bases = array_map(static fn (FieldBase $base): string => $base->value, FieldBase::cases());

    expect(array_diff($bases, $core))->toBe([])
        ->and(contributedCoverageProblems(generatorMappings()))->toBe([])
        ->and(array_keys(shapeOfEveryBase()))->toBe($bases)
        ->and(array_map(static fn (FieldShape $shape): string => ShapeOptions::of($shape)->typeName(), shapeOfEveryBase()))->toBe(array_combine($bases, $bases));
});

it('fails when a generator does not map a base an addon\'s field type can take', function (): void {
    $mappings = generatorMappings();
    unset($mappings[PhpRecords::class]['fieldTypes']['integer'], $mappings[TypeScriptContracts::class]['fieldTypes']['select']);

    expect(contributedCoverageProblems($mappings))->toBe([
        PhpRecords::class.' has no mapping for the base "integer" of an addon\'s field type.',
        TypeScriptContracts::class.' has no mapping for the base "select" of an addon\'s field type.',
    ]);
});
