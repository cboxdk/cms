<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\CursorKey;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Override;

/**
 * The testkit's TypeTableReader: the released rows of the shared variant a test gives it, in
 * memory, over the types of a TypeCatalog such as the FakeTypeCatalog. It runs the shared suite
 * TypeTableReaderContract, so a test of code that reads through the reader, a generated query
 * builder among them, sees what the kernel's reader on Postgres gives: the same refusals, the same
 * access predicate, filters, order with null as the greatest value, keyset pagination and fields,
 * each row's fields as the context may read them. It writes no read audit.
 *
 * Text compares byte by byte, as Postgres compares it under the C collation; a test that depends
 * on a language's collation belongs on Postgres.
 */
#[Experimental]
final readonly class FakeTypeTableReader implements TypeTableReader
{
    /**
     * @param  array<string, list<TypeTableSeed>>  $rows  by type name
     */
    public function __construct(
        private TypeCatalog $catalog,
        private array $rows = [],
    ) {}

    /**
     * A reader that also holds the rows of the type.
     *
     * @throws InvalidTypeTableQuery when the catalog has no such type
     */
    public function with(TypeDefinition $type, TypeTableSeed ...$rows): self
    {
        if (! $this->catalog->named($type->name) instanceof TypeDefinition) {
            throw InvalidTypeTableQuery::unknownType($type->name);
        }

        $held = $this->rows;
        $held[$type->name->value] = [...$held[$type->name->value] ?? [], ...array_values($rows)];

        return new self($this->catalog, $held);
    }

    #[Override]
    public function page(TypeTableQuery $query, AccessContext $access): TypeTablePage
    {
        $type = $this->catalog->named($query->type);

        if (! $type instanceof TypeDefinition) {
            throw InvalidTypeTableQuery::unknownType($query->type);
        }

        $query->assertAllowedBy($type);
        $query->assertReadableBy($type, $access);
        $actor = $access->principal instanceof ActorPrincipal ? $access->principal->actor : null;

        if ((! $actor instanceof ActorId && $access->regions === []) || ! $query->variant->isShared()) {
            return new TypeTablePage([], null);
        }

        $rows = array_values(array_filter(
            $this->rows[$type->name->value] ?? [],
            fn (TypeTableSeed $row): bool => $this->reaches($row, $actor, $access) && $this->matches($type, $row, $query->filters),
        ));
        usort($rows, static fn (TypeTableSeed $a, TypeTableSeed $b): int => self::compare($query->order, self::keys($type, $a, $query->order), $a->entry->toString(), self::keys($type, $b, $query->order), $b->entry->toString()));

        if ($query->after instanceof TypeTableCursor) {
            $after = $query->after;
            $values = array_map(static fn (CursorKey $key): FieldValue => $key->value, $after->keys);
            $rows = array_values(array_filter(
                $rows,
                static fn (TypeTableSeed $row): bool => self::compare($query->order, self::keys($type, $row, $query->order), $row->entry->toString(), $values, $after->entry->toString()) > 0,
            ));
        }

        $page = array_slice($rows, 0, $query->limit);
        $last = $page === [] ? null : $page[count($page) - 1];
        $next = count($rows) > $query->limit && $last instanceof TypeTableSeed
            ? new TypeTableCursor($last->entry, ...array_map(
                static fn (ColumnOrder $key): CursorKey => new CursorKey($key->column, self::value($type, $last->fields, $key->column)),
                $query->order,
            ))
            : null;

        return new TypeTablePage(
            array_map(static fn (TypeTableSeed $row): TypeTableRow => new TypeTableRow($row->entry, $type->readable(self::read($type, $row->fields), $access->classificationAccess, $access->readsAsAgent())), $page),
            $next,
        );
    }

    private function reaches(TypeTableSeed $row, ?ActorId $actor, AccessContext $access): bool
    {
        if ($actor instanceof ActorId && $row->owner instanceof ActorId && $row->owner->equals($actor)) {
            return true;
        }

        return $access->reaches($row->home);
    }

    /**
     * @param  list<ColumnFilter>  $filters
     */
    private function matches(TypeDefinition $type, TypeTableSeed $row, array $filters): bool
    {
        foreach ($filters as $filter) {
            $value = self::value($type, $row->fields, $filter->column);
            $null = $value instanceof NullValue;
            $compare = static fn (FieldValue $other): int => self::compareValues($filter->column, $value, $other);

            $matched = match ($filter->operator) {
                FilterOperator::IsNull => $null,
                FilterOperator::IsNotNull => ! $null,
                FilterOperator::Eq => ! $null && $compare($filter->values[0]) === 0,
                FilterOperator::Neq => ! $null && $compare($filter->values[0]) !== 0,
                FilterOperator::Lt => ! $null && $compare($filter->values[0]) < 0,
                FilterOperator::Lte => ! $null && $compare($filter->values[0]) <= 0,
                FilterOperator::Gt => ! $null && $compare($filter->values[0]) > 0,
                FilterOperator::Gte => ! $null && $compare($filter->values[0]) >= 0,
                FilterOperator::In => ! $null && array_any($filter->values, static fn (FieldValue $other): bool => $compare($other) === 0),
                FilterOperator::NotIn => ! $null && array_all($filter->values, static fn (FieldValue $other): bool => $compare($other) !== 0),
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order of two rows by the keys, null as the greatest value, and then by the entry id in
     * the direction of the last key.
     *
     * @param  list<ColumnOrder>  $order
     * @param  list<FieldValue>  $a
     * @param  list<FieldValue>  $b
     */
    private static function compare(array $order, array $a, string $entryA, array $b, string $entryB): int
    {
        $direction = SortDirection::Ascending;

        foreach ($order as $index => $key) {
            $direction = $key->direction;
            $left = $a[$index];
            $right = $b[$index];
            $result = match (true) {
                $left instanceof NullValue && $right instanceof NullValue => 0,
                $left instanceof NullValue => 1,
                $right instanceof NullValue => -1,
                default => self::compareValues($key->column, $left, $right),
            };

            if ($result !== 0) {
                return $direction === SortDirection::Descending ? -$result : $result;
            }
        }

        $result = strcmp($entryA, $entryB) <=> 0;

        return $direction === SortDirection::Descending ? -$result : $result;
    }

    /**
     * @throws InvalidTypeTableQuery when the values are of different kinds, or of a kind that does
     *                               not compare
     */
    private static function compareValues(string $column, FieldValue $a, FieldValue $b): int
    {
        return match (true) {
            $a instanceof TextValue && $b instanceof TextValue => strcmp($a->value, $b->value) <=> 0,
            $a instanceof IntegerValue && $b instanceof IntegerValue => $a->value <=> $b->value,
            $a instanceof DecimalValue && $b instanceof DecimalValue => self::compareDecimals($a->value, $b->value),
            $a instanceof BooleanValue && $b instanceof BooleanValue => $a->value <=> $b->value,
            $a instanceof DateValue && $b instanceof DateValue => strcmp($a->value, $b->value) <=> 0,
            $a instanceof DateTimeValue && $b instanceof DateTimeValue => [$a->value->getTimestamp(), (int) $a->value->format('u')] <=> [$b->value->getTimestamp(), (int) $b->value->format('u')],
            default => throw InvalidTypeTableQuery::value($column, $b::class),
        };
    }

    /**
     * Two decimals in canonical form, as numbers.
     */
    private static function compareDecimals(string $a, string $b): int
    {
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        [$wholeA, $fractionA] = array_pad(explode('.', ltrim($a, '-')), 2, '');
        [$wholeB, $fractionB] = array_pad(explode('.', ltrim($b, '-')), 2, '');
        $width = max(strlen($fractionA), strlen($fractionB));
        $magnitude = [strlen($wholeA), $wholeA, str_pad($fractionA, $width, '0')] <=> [strlen($wholeB), $wholeB, str_pad($fractionB, $width, '0')];

        return $negativeA ? -$magnitude : $magnitude;
    }

    /**
     * The row's values of the order's columns.
     *
     * @param  list<ColumnOrder>  $order
     * @return list<FieldValue>
     */
    private static function keys(TypeDefinition $type, TypeTableSeed $row, array $order): array
    {
        return array_map(static fn (ColumnOrder $key): FieldValue => self::value($type, $row->fields, $key->column), $order);
    }

    /**
     * The value of the field in the column, or NullValue when the row holds none.
     */
    private static function value(TypeDefinition $type, FieldValues $fields, string $column): FieldValue
    {
        $field = TypeTableQuery::fieldOf($type, $column);

        return self::held($field, $fields) ?? new NullValue;
    }

    private static function held(FieldDefinition $field, FieldValues $fields): ?FieldValue
    {
        return $field->namespace instanceof FieldNamespace
            ? $fields->extension($field->namespace)?->get($field->handle)
            : $fields->own->get($field->handle);
    }

    /**
     * The fields as a reader gives them: every field with a column, NullValue where the row holds
     * none, and no encrypted field.
     */
    private static function read(TypeDefinition $type, FieldValues $fields): FieldValues
    {
        $own = [];
        $namespaces = [];
        $extensions = [];

        foreach ($type->fields as $field) {
            if (! $field->column instanceof ColumnDefinition || $field->encrypted) {
                continue;
            }

            $named = new NamedValue($field->handle, self::held($field, $fields) ?? new NullValue);

            if ($field->namespace instanceof FieldNamespace) {
                $namespaces[$field->namespace->value] = $field->namespace;
                $extensions[$field->namespace->value][] = $named;
            } else {
                $own[] = $named;
            }
        }

        $fieldsOf = [];

        foreach ($extensions as $namespace => $named) {
            $fieldsOf[] = new ExtensionFields($namespaces[$namespace], new FieldMap(...$named));
        }

        return new FieldValues(new FieldMap(...$own), ...$fieldsOf);
    }
}
