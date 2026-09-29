<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A hook of the authorize phase (GUARDRAILS 2.4, PRD 6.2 phase 2, 6.3), declared with
 * #[Hook(command: ..., phase: Phase::Authorize, ...)]. It sees the pending plan after the kernel's
 * own authorization allowed the command, and may deny it with a reason. It can never grant
 * anything: the kernel's refusal stands whatever a hook answers, and HookDecision has no grant.
 *
 * A hook is deterministic, does no IO and answers within its budget (PRD 6.3).
 */
#[Experimental]
interface AuthorizeHook
{
    public function authorize(PlanView $plan): HookDecision;
}
