<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * One page of the released rows of a type table (PRD 5.10, 8.8, 11.6), as the generated query
 * builder of a type asks the TypeTableReader for it: the type, the variant (the shared one for a
 * type without localization), the filters, all of which a row must match, the order, the cursor of
 * the page before, and how many rows the page holds.
 *
 * The order is keyset pagination: the rows sort by the order's columns and then by the entry id,
 * which is unique, so every row has one place and a page continues exactly after the row its cursor
 * names. A page holds at most MAX_LIMIT rows.
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
     * Refuses the query unless the type is the query's and every filter is on a filterable field
     * of it and every key of the order on a sortable field of it (PRD 8.8), as a reader checks it
     * before it reads.
     *
     * @throws InvalidTypeTableQuery
     */
    public function assertAllowedBy(TypeDefinition $type): void
    {
        if (! $type->name->equals($this->type)) {
            throw InvalidTypeTableQuery::unknownType($this->type);
        }

        foreach ($this->filters as $filter) {
            if (! self::fieldOf($type, $filter->column)->filterable) {
                throw InvalidTypeTableQuery::notFilterable($this->type, $filter->column);
            }
        }

        foreach ($this->order as $key) {
            if (! self::fieldOf($type, $key->column)->sortable) {
                throw InvalidTypeTableQuery::notSortable($this->type, $key->column);
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
