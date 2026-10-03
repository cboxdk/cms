<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\ListedRole;

/**
 * What role.list and grant.list read (PRD 5.10), in the read transaction under its actor context.
 * Every actor reads the roles. A grant is read only when it has not ended, the context's regions
 * reach its node and a role of the actor whose permissions name grant.list reaches that node, as
 * PermissionRule decides it (PRD 5.10: a right is decided on the node), so an actor learns nothing
 * of the grants outside the part of the tree where it may list them, and its actor's profile only
 * when the context's classification access allows personal or it is the reader's own actor;
 * otherwise the profile is null.
 */
#[Internal]
interface AccessListings
{
    /**
     * At most $limit roles in the order of their ids, after $after when it is not null, each with
     * its permissions sorted.
     *
     * @return list<ListedRole>
     */
    public function roles(?RoleId $after, int $limit): array;

    /**
     * At most $limit grants in the order of their ids, after $after when it is not null.
     *
     * @return list<ListedGrant>
     */
    public function grants(?GrantId $after, int $limit): array;
}
