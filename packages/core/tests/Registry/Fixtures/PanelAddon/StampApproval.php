<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\DraftNote;
use Override;

/**
 * The addon's transform hook on notes.draft, which neither validates nor authorizes.
 */
#[Hook(command: DraftNote::class, phase: Phase::Transform, priority: 0, budgetMs: 2)]
final readonly class StampApproval implements TransformHook
{
    #[Override]
    public function transform(PlanView $plan): FieldChanges
    {
        return FieldChanges::none();
    }
}
