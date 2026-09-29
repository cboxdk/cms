<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A hook of the validate phase (GUARDRAILS 2.4, PRD 6.2 phase 5, 6.3), declared with
 * #[Hook(command: ..., phase: Phase::Validate, ...)]. It sees the plan after the transforms and
 * returns errors, which the kernel adds to its own as validation_hook_failed. It cannot remove an
 * error: the kernel's errors stand whatever a hook answers.
 *
 * A hook is deterministic, does no IO and answers within its budget (PRD 6.3).
 */
#[Experimental]
interface ValidateHook
{
    public function validate(PlanView $plan): HookErrors;
}
