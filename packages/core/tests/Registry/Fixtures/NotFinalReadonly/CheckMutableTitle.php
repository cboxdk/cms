<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Override;

/**
 * A valid hook for the command beside it, so a build of this root reports the command's shape
 * and nothing about the hook.
 */
#[Hook(command: MutableCommand::class, phase: Phase::Validate, priority: 0, budgetMs: 1)]
final readonly class CheckMutableTitle implements ValidateHook
{
    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
