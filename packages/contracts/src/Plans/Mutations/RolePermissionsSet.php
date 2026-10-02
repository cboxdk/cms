<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A role's permissions become the list given, each once (PRD 5.10): from the commit on, every grant
 * of the role gives these command and query names and no others. Each of its grants moves too
 * (GrantRoleContentChanged), so whatever compiled them compiles them again.
 */
#[Experimental]
final readonly class RolePermissionsSet implements Mutation
{
    /**
     * @param  list<CommandName>  $permissions
     *
     * @throws InvalidMutation
     */
    public function __construct(
        public RoleId $role,
        public array $permissions,
    ) {
        self::unique($role, $permissions);
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->role;
    }

    /**
     * Refuses a list of permissions that names one twice.
     *
     * @param  list<CommandName>  $permissions
     *
     * @throws InvalidMutation
     */
    public static function unique(RoleId $role, array $permissions): void
    {
        $seen = [];

        foreach ($permissions as $permission) {
            if (isset($seen[$permission->value])) {
                throw InvalidMutation::repeatedPermission($role, $permission);
            }

            $seen[$permission->value] = true;
        }
    }
}
