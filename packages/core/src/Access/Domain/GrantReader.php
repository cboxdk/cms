<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrants;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;

/**
 * What the grant and role commands read (PRD 5.10, 6.2 phase 1), under the actor context of the
 * command transaction. A grant is read only when the context's regions reach its node, so an actor
 * learns nothing of grants outside its part of the tree, except that a role command reads every
 * grant of its role; every actor reads the roles.
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

    /**
     * Every grant of the role that has not ended, whoever holds it and wherever it is, sorted by
     * id, with the version of the role's set of grants; the role commands must see them all, also
     * those outside the context's regions, because a change of the role reaches every holder.
     */
    public function roleGrants(RoleId $role): RoleGrants;

    /**
     * Whether a role has the handle.
     */
    public function handleTaken(RoleHandle $handle): bool;

    /**
     * The version of each actor's set of grants (ActorGrantsRef) by the actor's id, also of an
     * actor outside the context's regions, in one read: one, plus the versions of every grant of
     * the actor, ended ones included, plus the number that have ended. An actor without grants has
     * version 1.
     *
     * @param  list<ActorId>  $actors
     * @return array<string, AggregateVersion>
     */
    public function actorGrants(array $actors): array;
}
