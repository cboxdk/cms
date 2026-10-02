<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;

/**
 * What the grant commands read (PRD 5.10, 6.2 phase 1), under the actor context of the command
 * transaction. A grant is read only when the context's regions reach its node, so an actor learns
 * nothing of grants outside its part of the tree; every actor reads the roles.
 */
#[Internal]
interface GrantReader
{
    /**
     * The grant, ended or not, or null when no grant has the id or the context does not reach its
     * node.
     */
    public function grant(GrantId $grant): ?StoredGrant;

    /**
     * The role with its permissions, or null when no role has the id.
     */
    public function role(RoleId $role): ?StoredRole;

    /**
     * Whether the actor holds the role on the node with a grant that has not ended.
     */
    public function held(GrantSlotRef $slot): bool;
}
