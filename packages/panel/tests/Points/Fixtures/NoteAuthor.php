<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points\Fixtures;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;

/**
 * The author of a note in the note card's props: an object a point's props hold.
 */
#[Experimental]
final readonly class NoteAuthor
{
    public function __construct(
        public ActorClass $actorClass,
        public string $name,
    ) {}
}
