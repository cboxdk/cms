<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrants;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Override;

/**
 * The GrantReader in memory: the grants, roles and handles a test adds, read as the actor of a
 * context whose regions reach the nodes given (GrantReaderBehaviour holds it to PostgresGrantReader).
 */
final class FakeGrantReader implements GrantReader
{
    /** @var array<string, StoredGrant> */
    private array $grants = [];

    /** @var array<string, StoredRole> */
    private array $roles = [];

    /** @var array<string, true> */
    private array $reached = [];

    /** @var array<string, true> */
    private array $handles = [];

    /** @var array<string, int> the number of grants ever added of each role, by its id */
    private array $given = [];

    /**
     * @param  list<NodeId>  $reached  the nodes the context's regions reach
     */
    public function __construct(array $reached = [])
    {
        foreach ($reached as $node) {
            $this->reached[$node->toString()] = true;
        }
    }

    /**
     * Adds the role, its permissions sorted as the reader gives them, with its handle.
     */
    public function addRole(StoredRole $role, ?RoleHandle $handle = null): self
    {
        if ($handle instanceof RoleHandle) {
            $this->handles[$handle->value] = true;
        }

        $permissions = $role->permissions;
        usort($permissions, static fn (CommandName $a, CommandName $b): int => $a->value <=> $b->value);
        $this->roles[$role->id->toString()] = new StoredRole($role->id, $role->ceiling, $permissions, $role->version);

        return $this;
    }

    public function addGrant(StoredGrant $grant): self
    {
        if (! isset($this->grants[$grant->id->toString()])) {
            $this->given[$grant->role->toString()] = ($this->given[$grant->role->toString()] ?? 0) + 1;
        }

        $this->grants[$grant->id->toString()] = $grant;

        return $this;
    }

    /**
     * Lets the context reach the node too, as for a grant the actor issued on it.
     */
    public function reach(NodeId $node): self
    {
        $this->reached[$node->toString()] = true;

        return $this;
    }

    #[Override]
    public function grant(GrantId $grant): ?StoredGrant
    {
        $stored = $this->grants[$grant->toString()] ?? null;

        return $stored instanceof StoredGrant && isset($this->reached[$stored->node->toString()]) ? $stored : null;
    }

    #[Override]
    public function role(RoleId $role): ?StoredRole
    {
        return $this->roles[$role->toString()] ?? null;
    }

    #[Override]
    public function held(GrantSlotRef $slot): bool
    {
        return array_any($this->grants, static fn (StoredGrant $grant): bool => ! $grant->ended
            && $grant->actor->equals($slot->actor)
            && $grant->role->equals($slot->role)
            && $grant->node->equals($slot->node));
    }

    #[Override]
    public function roleGrants(RoleId $role): RoleGrants
    {
        $grants = array_values(array_filter($this->grants, static fn (StoredGrant $grant): bool => ! $grant->ended && $grant->role->equals($role)));
        usort($grants, static fn (StoredGrant $a, StoredGrant $b): int => $a->id->toString() <=> $b->id->toString());

        return new RoleGrants($grants, new AggregateVersion(($this->given[$role->toString()] ?? 0) + 1));
    }

    #[Override]
    public function handleTaken(RoleHandle $handle): bool
    {
        return isset($this->handles[$handle->value]);
    }

    #[Override]
    public function actorGrants(array $actors): array
    {
        $versions = [];

        foreach ($actors as $actor) {
            $version = 1;

            foreach ($this->grants as $grant) {
                if ($grant->actor->equals($actor)) {
                    $version += $grant->version->value + ($grant->ended ? 1 : 0);
                }
            }

            $versions[$actor->toString()] = new AggregateVersion($version);
        }

        return $versions;
    }
}
