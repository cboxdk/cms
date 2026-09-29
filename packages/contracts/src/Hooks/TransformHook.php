<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A hook of the transform phase (GUARDRAILS 2.4, PRD 6.2 phase 4, 6.3), declared with
 * #[Hook(command: ..., phase: Phase::Transform, ...)]. It returns changes to fields of the
 * revisions in the pending plan, such as a slug derived from a title, and nothing else: a change
 * names a revision of the plan and a field its type declares and the hook can see, so it cannot
 * change the actor, grants, classification or an aggregate the plan does not touch (invariant 12).
 * The kernel refuses the command with hook_change_refused when a change breaks that, and validates
 * the plan again after every transform, so a hook never produces a state that breaks a rule.
 *
 * A hook is deterministic, does no IO and answers within its budget (PRD 6.3).
 */
#[Experimental]
interface TransformHook
{
    public function transform(PlanView $plan): FieldChanges;
}
