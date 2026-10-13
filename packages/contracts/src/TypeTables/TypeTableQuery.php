<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * One page of the released rows of a type table (PRD 5.10, 8.8, 11.6), as the generated query
 * builder of a type asks the TypeTableReader for it: the type, the variant, which is the shared one
 * for a type without localization and a language's for a type with localization variants, whose
 * rows are one per language with the shared fields in each (PRD 10.1, 11.6), the filters, all of
 * which a row must match, the order, the cursor of the page before, and how many rows the page
 * holds.
 *
 * The order is keyset pagination: the rows sort by the order's columns and then by the entry id,
 * which is unique, so every row has one place and a page continues exactly after the row its cursor
 * names. A page holds at most MAX_LIMIT rows.
 *
 * The relation filters (PRD 5.11) keep the rows whose filterable relation points to a target. When
 * the order is the one key of a relation filter's sort_by field, the query is the target's reverse
 * listing (sortedRelation()): a reader orders by the sort key on the relation's edges, which holds
 * the sort_by field's released value, so the order and the cursor are the field's.
 */
#[Experimental]
final readonly class TypeTableQuery
{
    /** The most rows one page holds. */
    public const int MAX_LIMIT = 100;

    /** The rows a page holds when the builder says nothing. */
    public const int DEFAULT_LIMIT = 20;

    private const string COLUMN = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * @param  list<ColumnFilter>  $filters
     * @param  list<ColumnOrder>  $order
     * @param  list<RelationFilter>  $relations
     *
     * @throws InvalidTypeTableQuery
     */
    public function __construct(
        public TypeName $type,
        public VariantKey $variant,
        public array $filters = [],
        public array $order = [],
        public ?TypeTableCursor $after = null,
        public int $limit = self::DEFAULT_LIMIT,
        public array $relations = [],
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidTypeTableQuery::limit($limit);
        }

        $columns = [];

        foreach ($order as $key) {
            if (isset($columns[$key->column])) {
                throw InvalidTypeTableQuery::repeatedOrder($key->column);
            }

            $columns[$key->column] = true;
        }

        if ($after instanceof TypeTableCursor && ! $after->continues($order)) {
            throw InvalidTypeTableQuery::cursor();
        }
    }

    /**
     * Refuses the query unless the type is the query's, the type's table has rows of the query's
     * variant (TypeDefinition::tableHolds()), every filter is on a filterable field of it and every key of the
     * order on a sortable field of it (PRD 8.8), and every relation filter on a filterable relation
     * with a sort_by field and on filterable attributes of its targets (PRD 5.11), as a reader
     * checks it before it reads.
     *
     * @throws InvalidTypeTableQuery
     */
    public function assertAllowedBy(TypeDefinition $type): void
    {
        if (! $type->name->equals($this->type)) {
            throw InvalidTypeTableQuery::unknownType($this->type);
        }

        if (! $type->tableHolds($this->variant)) {
            throw InvalidTypeTableQuery::variant($this->type, $this->variant, $type->capabilities->localization);
        }

        foreach ($this->filters as $filter) {
            $field = self::fieldOf($type, $filter->column);

            // A relation is filtered by its target, through a RelationFilter.
            if (! $field->filterable || $field->isRelation()) {
                throw InvalidTypeTableQuery::notFilterable($this->type, $filter->column);
            }
        }

        foreach ($this->order as $key) {
            if (! self::fieldOf($type, $key->column)->sortable) {
                throw InvalidTypeTableQuery::notSortable($this->type, $key->column);
            }
        }

        foreach ($this->relations as $relation) {
            $field = self::fieldOf($type, $relation->column);

            if (! $field->isRelation() || ! $field->filterable || ! $type->sortKeyOf($field) instanceof FieldDefinition) {
                throw InvalidTypeTableQuery::notFilterable($this->type, $relation->column);
            }

            foreach ($relation->attributes as $attribute) {
                $declared = array_find($field->relationMeta(), static fn (FieldDefinition $meta): bool => $meta->handle->equals($attribute->attribute));

                if (! $declared instanceof FieldDefinition || ! $declared->filterable) {
                    throw InvalidTypeTableQuery::attributeNotFilterable($this->type, $relation->column, $attribute->attribute->value);
                }
            }
        }
    }

    /**
     * The relation filter the query is the reverse listing of, ordered by its sort key: the first
     * whose relation's sort_by field is the order's one key; null when there is none.
     */
    public function sortedRelation(TypeDefinition $type): ?RelationFilter
    {
        if (count($this->order) !== 1) {
            return null;
        }

        $column = $this->order[0]->column;

        return array_find($this->relations, static function (RelationFilter $relation) use ($type, $column): bool {
            $sortKey = $type->sortKeyOf(self::fieldOf($type, $relation->column));

            return $sortKey?->column instanceof ColumnDefinition && $sortKey->column->name === $column;
        });
    }

    /**
     * Refuses the query unless the context may read every field it filters on or orders by, and
     * so every field its cursor holds (PRD 6.2, 12.2), as a reader checks it before it reads: a
     * filter or an order on a field the reader would leave out of the rows would tell the caller
     * about its values.
     *
     * @throws InvalidTypeTableQuery
     */
    public function assertReadableBy(TypeDefinition $type, AccessContext $access): void
    {
        $agent = $access->readsAsAgent();
        $columns = [
            ...array_map(static fn (ColumnFilter $filter): string => $filter->column, $this->filters),
            ...array_map(static fn (ColumnOrder $key): string => $key->column, $this->order),
            ...array_map(static fn (RelationFilter $relation): string => $relation->column, $this->relations),
        ];

        foreach ($columns as $column) {
            if (! self::fieldOf($type, $column)->readableBy($access->classificationAccess, $agent)) {
                throw InvalidTypeTableQuery::notReadable($this->type, $column);
            }
        }
    }

    /**
     * @throws InvalidTypeTableQuery when the name is not a column of a type table
     */
    public static function assertColumn(string $column): void
    {
        if (preg_match(self::COLUMN, $column) !== 1 || strlen($column) > 63) {
            throw InvalidTypeTableQuery::column($column);
        }
    }

    /**
     * The top-level field of the type whose column has the name, the field a filter, an order key
     * or a cursor key on the column is about.
     *
     * @throws InvalidTypeTableQuery when no field has it
     */
    public static function fieldOf(TypeDefinition $type, string $column): FieldDefinition
    {
        foreach ($type->fields as $field) {
            if ($field->column instanceof ColumnDefinition && $field->column->name === $column) {
                return $field;
            }
        }

        throw InvalidTypeTableQuery::unknownColumn($type->name, $column);
    }
}
