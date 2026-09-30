<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One record a generated query builder read (PRD 11.12): the entry and its record, typed as the
 * owner's interface of the type.
 *
 * @template TRecord of object
 */
#[Experimental]
final readonly class EntryRecord
{
    /**
     * @param  TRecord  $record
     */
    public function __construct(
        public EntryId $entry,
        public object $record,
    ) {}
}
