<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of note.find: whether a note has the title.
 */
final readonly class FoundNote implements Result
{
    public function __construct(
        public bool $found,
    ) {}
}
