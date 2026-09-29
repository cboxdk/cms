<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;

/**
 * A transform hook of the notes package on its own command, with priority 20 and a budget of 5 ms.
 * It implements TransformHook, the interface of its phase; see the hooks page for what it can do.
 */
#[Hook(command: PublishNote::class, phase: Phase::Transform, priority: 20, budgetMs: 5)]
final readonly class TrimNoteTitle implements TransformHook
{
    public function transform(PlanView $plan): FieldChanges
    {
        return FieldChanges::none();
    }
}
