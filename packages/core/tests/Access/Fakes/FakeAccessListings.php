<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\ListedRole;
use Override;

/**
 * AccessListings in memory, read as the actor of a context whose regions reach the nodes given,
 * whose classification access does or does not allow personal, and which does or does not hold
 * grant.list (AccessListingsBehaviour holds it to PostgresAccessListings). A test adds the roles
 * and the grants with every profile; grants() gives the grants that have not ended on a reached
 * node, with a profile only for the reader's own actor or at personal access.
 */
final class FakeAccessListings implements AccessListings
{
    /** @var array<string, ListedRole> by id */
    private array $roles = [];

    /** @var array<string, array{ListedGrant, bool}> by id, with whether it has ended */
    private array $grants = [];

    /** @var array<string, true> */
    private array $reached = [];

    /**
     * @param  list<NodeId>  $reached  the nodes the context's regions reach
     */
    public function __construct(
        private readonly ActorId $reader,
        array $reached,
        private readonly bool $personal,
        private readonly bool $holdsGrantList,
    ) {
        foreach ($reached as $node) {
            $this->reached[$node->toString()] = true;
        }
    }

    /**
     * Adds the role, its permissions sorted as the listing gives them.
     */
    public function addRole(ListedRole $role): self
    {
        $permissions = $role->permissions;
        usort($permissions, static fn (CommandName $a, CommandName $b): int => $a->value <=> $b->value);
        $this->roles[$role->id->toString()] = new ListedRole($role->id, $role->handle, $role->ceiling, $permissions, $role->version);

        return $this;
    }

    public function addGrant(ListedGrant $grant, bool $ended = false): self
    {
        $this->grants[$grant->id->toString()] = [$grant, $ended];

        return $this;
    }

    #[Override]
    public function roles(?RoleId $after, int $limit): array
    {
        $roles = $this->roles;
        ksort($roles, SORT_STRING);

        return array_slice(array_values(array_filter(
            $roles,
            static fn (ListedRole $role): bool => ! $after instanceof RoleId || strcmp($role->id->toString(), $after->toString()) > 0,
        )), 0, $limit);
    }

    #[Override]
    public function grants(?GrantId $after, int $limit): array
    {
        if (! $this->holdsGrantList) {
            return [];
        }

        $grants = $this->grants;
        ksort($grants, SORT_STRING);
        $listed = [];

        foreach ($grants as $id => [$grant, $ended]) {
            if ($ended || ! isset($this->reached[$grant->node->toString()]) || ($after instanceof GrantId && strcmp($id, $after->toString()) <= 0)) {
                continue;
            }

            $listed[] = $this->personal || $grant->actor->equals($this->reader) ? $grant : new ListedGrant(
                $grant->id,
                $grant->actor,
                null,
                $grant->role,
                $grant->roleHandle,
                $grant->node,
                $grant->nodeLabel,
                $grant->effect,
                $grant->locales,
                $grant->version,
            );
        }

        return array_slice($listed, 0, $limit);
    }
}
