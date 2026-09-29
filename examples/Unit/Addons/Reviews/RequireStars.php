<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Examples\Unit\Build\Notes\PublishNote;

/**
 * The reviews addon's validate hook on the notes package's command. The manifest allows it; the
 * kernel gives it the plan with the fields up to internal, whatever the actor may read.
 */
#[Hook(command: PublishNote::class, phase: Phase::Validate, priority: 30, budgetMs: 2)]
final readonly class RequireStars implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
