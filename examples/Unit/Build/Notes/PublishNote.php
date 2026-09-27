<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * The command that publishes a note, version 1 of note.publish.
 */
#[Command('note.publish', version: 1)]
final readonly class PublishNote
{
    public function __construct(
        public string $title,
    ) {}
}
