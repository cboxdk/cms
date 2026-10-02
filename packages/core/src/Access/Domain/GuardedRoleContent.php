<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Core\Access\Domain\Dto\RoleContentChange;

/**
 * The aggregates of a command that creates a role or changes what it gives (PRD 5.10, invariant
 * 31), which the kernel's command authorizer holds to the EscalationGuard after the command's own
 * permission.
 */
#[Internal]
interface GuardedRoleContent extends Aggregates
{
    /**
     * What the command changes, or null when it changes nothing the guard holds: a role it did
     * not read, which the kernel rejects as version_conflict.
     */
    public function roleContent(): ?RoleContentChange;
}
