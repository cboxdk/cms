<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Override;

/**
 * The fixture hook for the registry tests.
 */
#[Hook(command: CreateNote::class, phase: Phase::Transform, priority: 10, budgetMs: 5)]
final readonly class TrimNoteTitle implements TransformHook
{
    #[Override]
    public function transform(PlanView $plan): FieldChanges
    {
        return FieldChanges::none();
    }
}
