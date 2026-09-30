<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One page of a type table query (PRD 8.8): its rows in the query's order, and the cursor of the
 * next page, or null when no row follows.
 */
#[Experimental]
final readonly class TypeTablePage
{
    /**
     * @param  list<TypeTableRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?TypeTableCursor $next,
    ) {}
}
