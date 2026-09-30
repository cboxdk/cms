<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\NullValue;

/**
 * The value of one order column in the last row of a page (PRD 8.8): text, an integer, a decimal,
 * a boolean, a date, a date-time, or null when the row holds none.
 */
#[Experimental]
final readonly class CursorKey
{
    /**
     * @throws InvalidTypeTableQuery
     */
    public function __construct(
        public string $column,
        public FieldValue $value,
    ) {
        TypeTableQuery::assertColumn($column);

        if (! $value instanceof NullValue) {
            ColumnFilter::assertComparable($column, $value);
        }
    }
}
