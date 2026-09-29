<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Tagging;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Examples\Unit\Build\Notes\PublishNote;

/**
 * A transform hook of the tagging package on the notes package's command. Its priority, 10, is
 * lower than that of the notes package's own hook, so it runs first.
 */
#[Hook(command: PublishNote::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class TagPublishedNote implements TransformHook
{
    public function transform(PlanView $plan): FieldChanges
    {
        return FieldChanges::none();
    }
}
