<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of note.find_title: the title, or null when there is no such note.
 */
final readonly class NoteTitle implements Result
{
    public function __construct(
        public ?string $title,
    ) {}
}
