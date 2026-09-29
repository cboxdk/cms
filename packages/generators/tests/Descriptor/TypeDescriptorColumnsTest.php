<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;

/*
 * The column names of the type descriptor (PRD 11.12, point 2). The descriptor's encoding of a
 * field's identity, (namespace, handle), into its column is injective: a type with the owner's
 * fields and the extension fields of many namespaces, whose handles and namespaces look like each
 * other run together, gets a different column for every field, each column decodes back to exactly
 * the namespace and the handle of its field, and no owner's field has the column of an extension
 * field. A column over 63 bytes is refused with generate_column_name_too_long, never cut short.
 */

/**
 * A text field of the owner below `schema/<owner>.yaml`.
 */
function columnField(string $owner, string $handle): FieldBlueprint
{
    return new FieldBlueprint(new Handle($handle), 'Field', null, false, Classification::Public, false, false, false, new TextOptions(null, 10, TextFormat::Plain), new Owner($owner), new SourceLocation('schema/'.$owner.'.yaml', '/fields/'.$handle));
}

/**
 * The namespaces and handles of the injectivity test: short ones, ones with digits and
 * underscores, ones that spell another's prefix, and ones at the maximum length.
 *
 * @return array{namespaces: list<string>, handles: list<string>}
 */
function columnIdentities(): array
{
    return [
        'namespaces' => ['a', 'a1', 'ab', 'app', 'b', 'e', 'ex', 'ext1', 'ap', 'pp', str_repeat('z', 20)],
        'handles' => ['a', 'b', 'a_b', 'ab', 'a_1', 'a1', 'app', 'app_a', 'ext_app', 'ext_app_a', 'b_ext', 'x_app_a', 'e', 'xt', str_repeat('h', 36)],
    ];
}

/**
 * The resolved type with every handle as the owner's field and as an extension field of every
 * namespace, in an order that is not the columns' order.
 */
function injectivityType(): ResolvedType
{
    ['namespaces' => $namespaces, 'handles' => $handles] = columnIdentities();
    $fields = [];

    foreach (array_reverse($handles) as $handle) {
        foreach ($namespaces as $namespace) {
            $fields[] = ResolvedField::extension(columnField($namespace, $handle));
        }

        $fields[] = ResolvedField::own(columnField('app', $handle));
    }

    return new ResolvedType(SchemaFixtures::type(SchemaFixtures::root(), 'thing', []), $fields, []);
}

it('gives every field of a type its own column, which decodes back to exactly its namespace and handle', function (): void {
    ['namespaces' => $namespaces, 'handles' => $handles] = columnIdentities();
    $type = DescriptorCompiler::compile(new ResolvedSchema([injectivityType()]))->types[0];
    $columns = [];

    foreach ($type->fields as $field) {
        $column = (string) $field->column?->name;
        $identity = ($field->namespace instanceof Owner ? $field->namespace->value : '').' '.$field->handle->value;

        Assert::assertArrayNotHasKey($column, $columns, sprintf('%s and %s both have the column %s.', $columns[$column] ?? '', $identity, $column));
        $columns[$column] = $identity;

        if (! $field->namespace instanceof Owner) {
            Assert::assertSame($field->handle->value, $column);
            Assert::assertStringStartsNotWith(Handle::RESERVED.ColumnName::SEPARATOR, $column);

            continue;
        }

        $parts = explode(ColumnName::SEPARATOR, $column, 3);

        Assert::assertSame([Handle::RESERVED, $field->namespace->value, $field->handle->value], $parts, $column);
        Assert::assertSame(1, preg_match(Owner::PATTERN, $parts[1]), $column);
        Assert::assertSame(1, preg_match(Handle::PATTERN, $parts[2]), $column);
    }

    $sorted = array_keys($columns);
    sort($sorted, SORT_STRING);

    expect($columns)->toHaveCount(count($handles) * (count($namespaces) + 1))
        ->and(array_keys($columns))->toBe($sorted)
        ->and(max([0, ...array_map(strlen(...), array_keys($columns))]))->toBe(ColumnName::MAX_BYTES);
});

it('keeps a column of exactly 63 bytes and refuses one of 64 with generate_column_name_too_long', function (): void {
    $namespace = str_repeat('n', 20);
    $fits = columnField($namespace, str_repeat('f', 36));
    $long = columnField($namespace, str_repeat('l', 37));
    $ownLong = columnField('app', str_repeat('o', 63));
    $compile = static fn (FieldBlueprint ...$fields): mixed => DescriptorCompiler::compile(new ResolvedSchema([new ResolvedType(
        SchemaFixtures::type(SchemaFixtures::root(), 'thing', []),
        array_values(array_map(static fn (FieldBlueprint $field): ResolvedField => $field->owner->value === 'app' ? ResolvedField::own($field) : ResolvedField::extension($field), $fields)),
        [],
    )]));
    $column = 'ext__'.$namespace.'__'.str_repeat('l', 37);

    expect(array_map(static fn (FieldDescriptor $field): ?string => $field->column?->name, $compile($fits, $ownLong)->types[0]->fields))
        ->toBe(['ext__'.$namespace.'__'.str_repeat('f', 36), str_repeat('o', 63)])
        ->and(strlen('ext__'.$namespace.'__'.str_repeat('f', 36)))->toBe(63)
        ->and(static fn (): mixed => $compile($fits, $long))
        ->toThrow(GenerationFailed::class, sprintf('[generate_column_name_too_long] schema/%s.yaml, /fields/%s: the column name %s has 64 bytes, and Postgres allows 63. Choose a shorter handle.', $namespace, str_repeat('l', 37), $column));
});

it('reports a column that is too long once, without also asking for its classification', function (): void {
    $field = new FieldBlueprint(new Handle(str_repeat('l', 37)), 'Field', null, false, null, false, false, false, new TextOptions(null, 10, TextFormat::Plain), new Owner(str_repeat('n', 20)), new SourceLocation('schema/long.yaml', '/fields/0'));
    $message = '';

    try {
        DescriptorCompiler::compile(new ResolvedSchema([new ResolvedType(SchemaFixtures::type(SchemaFixtures::root(), 'thing', []), [ResolvedField::extension($field)], [])]));
    } catch (GenerationFailed $failed) {
        $message = $failed->getMessage();
    }

    expect($message)->toContain("1 problem:\n[generate_column_name_too_long] schema/long.yaml, /fields/0:")
        ->and(substr_count($message, '[generate_'))->toBe(1);
});
