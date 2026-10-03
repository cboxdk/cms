<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use LogicException;

/**
 * The grants, roles' permissions and node paths the fake authorizers decide from, in memory: what
 * the Postgres authorizers read from grants, role_permissions and nodes. It has no row level
 * security, so every node it knows has its path.
 */
final class FakePermissions
{
    /** @var array<string, list<array{Grant, list<string>}>> by actor */
    private array $grants = [];

    /**
     * @param  array<string, NodePath>  $paths  the path of each node by its id
     */
    public function __construct(private readonly array $paths) {}

    /**
     * A grant to the actor of a role whose permissions are the names given.
     *
     * @param  list<CommandName>  $permissions
     */
    public function grant(ActorId $actor, Grant $grant, array $permissions): self
    {
        $this->grants[$actor->toString()][] = [$grant, array_map(static fn (CommandName $name): string => $name->value, $permissions)];

        return $this;
    }

    /**
     * The actor's grants of the roles whose permissions name the command or read.
     *
     * @return list<Grant>
     */
    public function of(ActorId $actor, CommandName $permission): array
    {
        $grants = [];

        foreach ($this->grants[$actor->toString()] ?? [] as [$grant, $permissions]) {
            if (in_array($permission->value, $permissions, true)) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * Every grant of the actor, with its role's permissions, as the escalation guard reads them.
     *
     * @return list<HeldGrant>
     */
    public function held(ActorId $actor): array
    {
        return array_map(
            static fn (array $held): HeldGrant => new HeldGrant($held[0], array_map(static fn (string $name): CommandName => new CommandName($name), $held[1])),
            $this->grants[$actor->toString()] ?? [],
        );
    }

    /**
     * The read of each actor's set of grants at its version, as PostgresGrants::sets() gives it:
     * one plus the versions of its grants, each at version 1 and none ended.
     *
     * @param  list<ActorId>  $actors
     * @return list<ReadVersion>
     */
    public function sets(array $actors): array
    {
        $reads = [];

        foreach ($actors as $actor) {
            $reads[$actor->toString()] = ReadVersion::at(new ActorGrantsRef($actor), new AggregateVersion(1 + count($this->grants[$actor->toString()] ?? [])));
        }

        ksort($reads);

        return array_values($reads);
    }

    /**
     * The path of a node it knows, by its id.
     */
    public function path(string $node): NodePath
    {
        return $this->paths[$node] ?? throw new LogicException(sprintf('The fake permissions know no node %s.', $node));
    }

    /**
     * @param  list<NodeId>  $nodes
     * @return array<string, NodePath>
     */
    public function paths(array $nodes): array
    {
        $paths = [];

        foreach ($nodes as $node) {
            if (isset($this->paths[$node->toString()])) {
                $paths[$node->toString()] = $this->paths[$node->toString()];
            }
        }

        return $paths;
    }
}
