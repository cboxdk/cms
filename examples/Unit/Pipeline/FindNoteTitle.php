<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * Asks for the title of a note. The actor is not a field: the query pipeline takes it from the
 * transport's authentication.
 */
final readonly class FindNoteTitle implements Query
{
    public function __construct(
        public EntryId $note,
    ) {}
}
