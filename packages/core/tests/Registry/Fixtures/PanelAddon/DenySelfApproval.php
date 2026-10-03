<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\DraftNote;
use Override;

/**
 * The addon's validate hook on notes.draft, which its blocking checks mirror.
 */
#[Hook(command: DraftNote::class, phase: Phase::Validate, priority: 0, budgetMs: 2)]
final readonly class DenySelfApproval implements ValidateHook
{
    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
