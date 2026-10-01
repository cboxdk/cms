<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;

/**
 * Asks for a published note, version 1 of note.find_published. It is a public read: anyone may run
 * it, the anonymous principal included, and row level security keeps it to what is published.
 */
#[QueryType('note.find_published', version: 1)]
final readonly class FindPublishedNote implements PublicQuery
{
    public function __construct(
        public EntryId $note,
    ) {}
}
