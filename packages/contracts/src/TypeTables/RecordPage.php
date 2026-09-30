<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One page a generated query builder read (PRD 8.8, 11.12): its records in the query's order, each
 * typed as the owner's interface of the type, and the cursor of the next page, or null when no
 * record follows.
 *
 * @template TRecord of object
 */
#[Experimental]
final readonly class RecordPage
{
    /**
     * @param  list<EntryRecord<TRecord>>  $records
     */
    public function __construct(
        public array $records,
        public ?TypeTableCursor $next,
    ) {}
}
