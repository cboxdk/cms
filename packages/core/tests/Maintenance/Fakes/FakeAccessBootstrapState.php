<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use LogicException;
use Override;

/**
 * The state the access bootstrap reads, as a test sets it: whether a staff member holds a grant,
 * the nodes that exist and the roles by handle. Like the Postgres one, it reads only under the
 * installation operator's context and throws for any other.
 */
final class FakeAccessBootstrapState implements AccessBootstrapState
{
    public bool $staffGranted = false;

    /** @var array<string, true> the ids of the nodes that exist */
    public array $nodes = [];

    /** @var array<string, ExistingRole> the roles by handle */
    public array $roles = [];

    /** How often read() was asked. */
    public int $reads = 0;

    public function __construct(public ?ActorId $operator = null) {}

    public function addNode(NodeId $node): void
    {
        $this->nodes[$node->toString()] = true;
    }

    #[Override]
    public function read(AccessContext $operator, NodeId $node, RoleHandle $role): BootstrapState
    {
        $principal = $operator->principal;

        if (! $this->operator instanceof ActorId || ! $principal instanceof ActorPrincipal || ! $principal->actor->equals($this->operator)) {
            throw new LogicException('The bootstrap state is read only under the actor context of the installation operator.');
        }

        $this->reads++;

        $found = $this->roles[$role->value] ?? null;

        if ($found instanceof ExistingRole) {
            $permissions = $found->permissions;
            usort($permissions, static fn (CommandName $a, CommandName $b): int => strcmp($a->value, $b->value));
            $found = new ExistingRole($found->id, $found->ceiling, $permissions);
        }

        return new BootstrapState($this->staffGranted, isset($this->nodes[$node->toString()]), $found);
    }
}
