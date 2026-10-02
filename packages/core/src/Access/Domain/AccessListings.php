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
 * Every actor reads the roles. A grant is read only when it has not ended and the context's
 * regions reach its node, so an actor learns nothing of the grants outside its part of the tree,
 * and its actor's profile only when the context's classification access allows personal and the
 * actor holds a role whose permissions name grant.list; otherwise the profile is null.
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
