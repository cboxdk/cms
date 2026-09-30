<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One key of a type table query's order (PRD 8.8): the column of a sortable field and its
 * direction. The entry id is always the last key, in the direction of the key before it.
 */
#[Experimental]
final readonly class ColumnOrder
{
    /**
     * @throws InvalidTypeTableQuery
     */
    public function __construct(
        public string $column,
        public SortDirection $direction = SortDirection::Ascending,
    ) {
        TypeTableQuery::assertColumn($column);
    }
}
