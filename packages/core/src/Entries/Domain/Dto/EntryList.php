<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of entry.list: a page of entries, newest first, and the id to read the next page
 * after, or null when this page is the last.
 */
#[Experimental]
final readonly class EntryList implements Result
{
    /**
     * @param  list<ListedEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public ?EntryId $next,
    ) {}
}
