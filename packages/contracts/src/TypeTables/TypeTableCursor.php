<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * Where the next page of a keyset-paginated type table query starts (PRD 8.8): the values of the
 * order columns in the last row of the page, and its entry id, the last key. The next page holds
 * the rows after that row in the query's order. A cursor is not a stable URL: it moves when content
 * is added.
 */
#[Experimental]
final readonly class TypeTableCursor
{
    /** @var list<CursorKey> */
    public array $keys;

    public function __construct(
        public EntryId $entry,
        CursorKey ...$keys,
    ) {
        $this->keys = array_values($keys);
    }

    /**
     * Whether the cursor holds a value for each column of the order, in the same order.
     *
     * @param  list<ColumnOrder>  $order
     */
    public function continues(array $order): bool
    {
        if (count($order) !== count($this->keys)) {
            return false;
        }

        return array_all($order, fn (ColumnOrder $key, $index): bool => $this->keys[$index]->column === $key->column);
    }
}
