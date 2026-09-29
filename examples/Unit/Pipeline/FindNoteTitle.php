<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * Asks for the title of a note, version 1 of note.find_title. The actor is not a field: the query
 * pipeline takes it from the transport's authentication.
 */
#[QueryType('note.find_title', version: 1)]
final readonly class FindNoteTitle implements Query
{
    public function __construct(
        public EntryId $note,
    ) {}
}
