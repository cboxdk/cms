<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * The action behind note.publish, with a REST endpoint and a CLI command. The registry lists the
 * surfaces in the order of the Surface cases, whatever order the attribute gives them in.
 */
#[Action(surfaces: [Surface::Cli, Surface::Rest])]
final readonly class PublishNoteAction {}
