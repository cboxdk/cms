<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;

/**
 * The fixture addon's authorize hook on grant.assign (PRD 6.2 phase 2, 13.4): a person may not
 * grant a role to themselves. The kernel's escalation guard lets an actor give what it holds, so
 * granting oneself a role one already holds the permissions of is allowed by the kernel; this
 * addon's rule, a four-eyes rule, is that every grant to a person is given by another. It denies
 * when the actor of any grant the plan assigns is the actor the command runs as; a grant issued on
 * behalf of others is judged by the issuing actor, the one the principal names. It grants nothing:
 * a denial is answered as unauthorized, and the kernel's refusals stand whatever it answers.
 *
 * The addon's form check fixtureaddon.self-grant in the panel mirrors it (the mirror rule, PRD
 * 13.4): it blocks the submit of grant.assign's form on the same documents, read against the
 * viewer's actor id the form's context names, and resources/panel/parity/self-grant.json holds
 * both to the same verdicts.
 */
#[Hook(command: AssignGrant::class, phase: Phase::Authorize, priority: 10, budgetMs: 2)]
final readonly class DenySelfGrant implements AuthorizeHook
{
    public function authorize(PlanView $plan): HookDecision
    {
        $principal = $plan->principal;

        if (! $principal instanceof ActorPrincipal) {
            return HookDecision::noObjection();
        }

        foreach ($plan->mutations as $mutation) {
            if ($mutation instanceof GrantAssigned && $mutation->actor->equals($principal->actor)) {
                return HookDecision::deny(sprintf(
                    'The actor %s may not grant a role to themselves: a grant to a person is given by another person (the fixture addon\'s four-eyes rule).',
                    $principal->actor->toString(),
                ));
            }
        }

        return HookDecision::noObjection();
    }
}
