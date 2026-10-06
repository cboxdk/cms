<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;

/**
 * The grants the actor of the read's context holds, each with its role's permissions (PRD 5.10,
 * 13.4), read inside the read transaction and under its actor context, so a read can learn
 * nothing but what its own actor holds: what action.list decides the actor's actions from with the
 * PermissionRule, as the query authorizer decides a read that needs a permission. Without an actor
 * context there are none.
 */
#[Internal]
interface OwnHeldGrants
{
    /**
     * @return list<HeldGrant> the grants that have not ended, in the order of their ids
     */
    public function held(): array;
}
