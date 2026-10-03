<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Override;

/**
 * The addon's authorize hook on its own command approvals.request.
 */
#[Hook(command: RequestApproval::class, phase: Phase::Authorize, priority: 0, budgetMs: 2)]
final readonly class RequireApprover implements AuthorizeHook
{
    #[Override]
    public function authorize(PlanView $plan): HookDecision
    {
        return HookDecision::noObjection();
    }
}
