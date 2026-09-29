<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The query that finds a note by its title, version 1 of note.find.
 */
#[QueryType('note.find', version: 1)]
final readonly class FindNote implements Query
{
    public function __construct(
        public string $title,
    ) {}
}
