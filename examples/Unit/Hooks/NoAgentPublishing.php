<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;

/**
 * Denies publishing to a credential an agent was issued. It can only deny: when it has no
 * objection, the kernel's own authorization still decides.
 */
#[Hook(command: PublishStory::class, phase: Phase::Authorize, priority: 0, budgetMs: 1)]
final readonly class NoAgentPublishing implements AuthorizeHook
{
    public function authorize(PlanView $plan): HookDecision
    {
        return $plan->principal instanceof ActorPrincipal && $plan->principal->issuerKind === IssuerKind::Agent
            ? HookDecision::deny('Stories are published by a person, not by an agent.')
            : HookDecision::noObjection();
    }
}
