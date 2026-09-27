<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A transform hook of the notes package on its own command, with priority 20 and a budget of 5 ms.
 */
#[Hook(command: PublishNote::class, phase: Phase::Transform, priority: 20, budgetMs: 5)]
final readonly class TrimNoteTitle {}
