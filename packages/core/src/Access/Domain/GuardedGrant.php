<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;

/**
 * The aggregates of a command that can give an actor a role (PRD 5.10, invariant 31), which the
 * kernel's command authorizer holds to the EscalationGuard after the command's own permission.
 */
#[Internal]
interface GuardedGrant extends Aggregates
{
    /**
     * What the command gives, or null when it gives nothing the guard holds: a deny, or a role that
     * does not exist, which the action refuses itself.
     */
    public function escalation(): ?RoleGrant;
}
