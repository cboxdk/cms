<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A role is created (PRD 5.10, 12.2): its handle, the highest classification its holders read
 * through it, and its permissions, the command and query names it may run, each once. It is
 * granted to nobody yet; a grant gives it on a node.
 */
#[Experimental]
final readonly class RoleCreated implements Mutation
{
    /**
     * @param  list<CommandName>  $permissions
     *
     * @throws InvalidMutation
     */
    public function __construct(
        public RoleId $role,
        public RoleHandle $handle,
        public ClassificationAccess $ceiling,
        public array $permissions,
    ) {
        RolePermissionsSet::unique($role, $permissions);
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->role;
    }
}
