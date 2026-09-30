<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\TypeTables;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\CursorKey;
use Cbox\Cms\Contracts\TypeTables\EntryRecord;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\RecordPage;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use DateTimeImmutable;
use stdClass;

/*
 * The query a generated builder hands the TypeTableReader (PRD 8.8): its filters, order, cursor
 * and limit hold their rules on construction, and assertAllowedBy() refuses what the type does not
 * allow before a reader reads.
 */

const ENTRY = '0192a0c0-0000-7000-8000-000000000301';

function tableType(): TypeDefinition
{
    $field = static fn (string $handle, bool $filterable, bool $sortable): FieldDefinition => new FieldDefinition(
        null,
        new FieldHandle($handle),
        'text',
        ClassificationAccess::Public,
        true,
        false,
        false,
        $filterable,
        $sortable,
        new ColumnDefinition($handle, 'text', false, []),
    );

    return new TypeDefinition(
        TypeId::fromString('0192a0c0-0000-7000-8000-00000000f001'),
        new TypeName('shop:item'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [],
        [$field('name', true, true), $field('size', true, false), $field('rank', false, true), $field('note', false, false)],
    );
}

/**
 * @param  list<ColumnFilter>  $filters
 * @param  list<ColumnOrder>  $order
 */
function tableQuery(array $filters = [], array $order = [], ?TypeTableCursor $after = null, int $limit = TypeTableQuery::DEFAULT_LIMIT, string $type = 'shop:item'): TypeTableQuery
{
    return new TypeTableQuery(new TypeName($type), VariantKey::shared(), $filters, $order, $after, $limit);
}

it('takes one value for a comparison, one or more for in and nin, and none for null and not_null', function (FilterOperator $operator, int $values, bool $valid): void {
    $make = static fn (): ColumnFilter => new ColumnFilter('name', $operator, ...array_fill(0, $values, new TextValue('a')));

    if ($valid) {
        expect($make()->values)->toHaveCount($values);
    } else {
        expect($make)->toThrow(InvalidTypeTableQuery::class, 'The filter operator '.$operator->value.' takes ');
    }
})->with([
    'eq with one' => [FilterOperator::Eq, 1, true],
    'eq with none' => [FilterOperator::Eq, 0, false],
    'lt with two' => [FilterOperator::Lt, 2, false],
    'in with two' => [FilterOperator::In, 2, true],
    'in with none' => [FilterOperator::In, 0, false],
    'nin with one' => [FilterOperator::NotIn, 1, true],
    'null with none' => [FilterOperator::IsNull, 0, true],
    'not_null with one' => [FilterOperator::IsNotNull, 1, false],
]);

it('names the values an operator takes in its refusal', function (): void {
    expect(fn (): ColumnFilter => new ColumnFilter('name', FilterOperator::Gte))->toThrow(InvalidTypeTableQuery::class, 'The filter operator gte takes exactly one value, got 0.')
        ->and(fn (): ColumnFilter => new ColumnFilter('name', FilterOperator::In))->toThrow(InvalidTypeTableQuery::class, 'The filter operator in takes one or more values, got 0.')
        ->and(fn (): ColumnFilter => new ColumnFilter('name', FilterOperator::IsNull, new TextValue('a')))->toThrow(InvalidTypeTableQuery::class, 'The filter operator null takes no value, got 1.');
});

it('compares text, integers, decimals, booleans, dates and date-times, never null or a list', function (FieldValue $value, bool $comparable): void {
    $make = static fn (): ColumnFilter => new ColumnFilter('name', FilterOperator::Eq, $value);

    if ($comparable) {
        expect($make()->values)->toBe([$value]);
    } else {
        expect($make)->toThrow(InvalidTypeTableQuery::class, 'but the one for name is a '.$value::class.'.');
    }
})->with([
    'text' => [new TextValue('a'), true],
    'integer' => [new IntegerValue(1), true],
    'decimal' => [new DecimalValue('1.5'), true],
    'boolean' => [new BooleanValue(true), true],
    'date' => [new DateValue('2026-03-10'), true],
    'date-time' => [new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00Z')), true],
    'null' => [new NullValue, false],
    'list' => [new ListValue(new TextValue('a')), false],
]);

it('refuses a name that is not a column of a type table', function (string $column): void {
    expect(fn (): ColumnOrder => new ColumnOrder($column))->toThrow(InvalidTypeTableQuery::class, 'A column of a type table is a lowercase letter')
        ->and(fn (): ColumnFilter => new ColumnFilter($column, FilterOperator::IsNull))->toThrow(InvalidTypeTableQuery::class)
        ->and(fn (): CursorKey => new CursorKey($column, new NullValue))->toThrow(InvalidTypeTableQuery::class);
})->with([
    'empty' => [''],
    'upper case' => ['Name'],
    'a leading digit' => ['1name'],
    'a quote' => ['name"; drop'],
    'a leading underscore' => ['_name'],
    'longer than 63 bytes' => [str_repeat('a', 64)],
]);

it('takes a column name of 63 bytes and cuts a long one in the message', function (): void {
    expect(new ColumnOrder(str_repeat('a', 63))->column)->toBe(str_repeat('a', 63))
        ->and(fn (): ColumnOrder => new ColumnOrder(str_repeat('b', 70)))->toThrow(InvalidTypeTableQuery::class, 'got "'.str_repeat('b', 64).'...".');
});

it('orders ascending unless told otherwise', function (): void {
    expect(new ColumnOrder('name')->direction)->toBe(SortDirection::Ascending);
});

it('holds a cursor key of null or of a comparable value, and no list', function (): void {
    expect(new CursorKey('name', new NullValue)->value)->toBeInstanceOf(NullValue::class)
        ->and(new CursorKey('name', new IntegerValue(3))->value)->toEqual(new IntegerValue(3))
        ->and(fn (): CursorKey => new CursorKey('name', new ListValue))->toThrow(InvalidTypeTableQuery::class);
});

it('holds 1 to 100 rows a page, 20 by default', function (int $limit, bool $valid): void {
    if ($valid) {
        expect(tableQuery(limit: $limit)->limit)->toBe($limit);
    } else {
        expect(fn (): TypeTableQuery => tableQuery(limit: $limit))->toThrow(InvalidTypeTableQuery::class, sprintf('A page holds 1 to 100 rows, got %d.', $limit));
    }
})->with([[0, false], [1, true], [100, true], [101, false], [-5, false]]);

it('pages 20 rows by default, with no filter, order or cursor', function (): void {
    $query = new TypeTableQuery(new TypeName('shop:item'), VariantKey::shared());

    expect($query->limit)->toBe(20)
        ->and($query->filters)->toBe([])
        ->and($query->order)->toBe([])
        ->and($query->after)->toBeNull();
});

it('orders by each column once', function (): void {
    expect(fn (): TypeTableQuery => tableQuery(order: [new ColumnOrder('name'), new ColumnOrder('rank'), new ColumnOrder('name', SortDirection::Descending)]))
        ->toThrow(InvalidTypeTableQuery::class, 'A query orders by each column once, but orders by name twice.');
});

it('continues only the order its cursor was made for', function (array $keys, array $order, bool $continues): void {
    $keys = array_values(array_filter($keys, static fn (mixed $key): bool => $key instanceof CursorKey));
    $order = array_values(array_filter($order, static fn (mixed $key): bool => $key instanceof ColumnOrder));
    $cursor = new TypeTableCursor(EntryId::fromString(ENTRY), ...$keys);

    expect($cursor->continues($order))->toBe($continues);

    if ($continues) {
        expect(tableQuery(order: $order, after: $cursor)->after)->toBe($cursor);
    } else {
        expect(fn (): TypeTableQuery => tableQuery(order: $order, after: $cursor))->toThrow(InvalidTypeTableQuery::class, 'A cursor holds a value for each column of the query\'s order');
    }
})->with([
    'no keys, no order' => [[], [], true],
    'the same columns' => [[new CursorKey('name', new TextValue('a')), new CursorKey('rank', new NullValue)], [new ColumnOrder('name'), new ColumnOrder('rank', SortDirection::Descending)], true],
    'fewer keys' => [[new CursorKey('name', new TextValue('a'))], [new ColumnOrder('name'), new ColumnOrder('rank')], false],
    'more keys' => [[new CursorKey('name', new TextValue('a')), new CursorKey('rank', new NullValue)], [new ColumnOrder('name')], false],
    'another column' => [[new CursorKey('rank', new NullValue)], [new ColumnOrder('name')], false],
    'another order of the columns' => [[new CursorKey('rank', new NullValue), new CursorKey('name', new TextValue('a'))], [new ColumnOrder('name'), new ColumnOrder('rank')], false],
]);

it('allows filters on filterable fields and an order by sortable fields of its type', function (): void {
    tableQuery([new ColumnFilter('name', FilterOperator::IsNull), new ColumnFilter('size', FilterOperator::IsNull)], [new ColumnOrder('name'), new ColumnOrder('rank')])->assertAllowedBy(tableType());

    expect(TypeTableQuery::fieldOf(tableType(), 'size')->handle->value)->toBe('size');
});

it('refuses another type, a column that is no field\'s, a filter that is not filterable and an order that is not sortable', function (TypeTableQuery $query, string $message): void {
    expect(fn () => $query->assertAllowedBy(tableType()))->toThrow(InvalidTypeTableQuery::class, $message);
})->with([
    'another type' => [tableQuery(type: 'shop:other'), 'The installation has no type shop:other.'],
    'an unknown filter column' => [tableQuery([new ColumnFilter('colour', FilterOperator::IsNull)]), 'The type shop:item has no field with the column colour.'],
    'an unknown order column' => [tableQuery(order: [new ColumnOrder('colour')]), 'The type shop:item has no field with the column colour.'],
    'a filter on a sortable field' => [tableQuery([new ColumnFilter('rank', FilterOperator::IsNull)]), 'The field of shop:item in the column rank is not filterable'],
    'an order by a filterable field' => [tableQuery(order: [new ColumnOrder('size')]), 'The field of shop:item in the column size is not sortable'],
    'a field that is neither' => [tableQuery([new ColumnFilter('note', FilterOperator::IsNull)]), 'in the column note is not filterable'],
]);

it('tells which operators take one value and which a list', function (): void {
    expect(array_map(static fn (FilterOperator $operator): string => $operator->value, array_values(array_filter(FilterOperator::cases(), static fn (FilterOperator $operator): bool => $operator->takesOneValue()))))
        ->toBe(['eq', 'neq', 'lt', 'lte', 'gt', 'gte'])
        ->and(array_map(static fn (FilterOperator $operator): string => $operator->value, array_values(array_filter(FilterOperator::cases(), static fn (FilterOperator $operator): bool => $operator->takesValues()))))
        ->toBe(['in', 'nin']);
});

it('holds the records of a page with their entries and the next cursor', function (): void {
    $record = new stdClass;
    $cursor = new TypeTableCursor(EntryId::fromString(ENTRY));
    $page = new RecordPage([new EntryRecord(EntryId::fromString(ENTRY), $record)], $cursor);

    expect($page->records[0]->record)->toBe($record)
        ->and($page->records[0]->entry->toString())->toBe(ENTRY)
        ->and($page->next)->toBe($cursor)
        ->and($cursor->keys)->toBe([]);
});
