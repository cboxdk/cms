<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRoleContentChanged;
use Cbox\Cms\Contracts\Plans\Mutations\RolePermissionsSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrants;
use Cbox\Cms\Core\Access\Domain\Dto\SetRolePermissionsAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Override;

/**
 * The write action of role.set_permissions (PRD 5.10, 6.2). resolve() reads the role with its
 * permissions, every grant of it that has not ended and the version of each holder's set of grants
 * through the GrantReader, and which of the new permissions the registry knows through the
 * PermissionCatalog. The kernel's authorize step
 * holds the issuing actor to role.set_permissions on some node and each added permission to the
 * escalation guard on every node where the role is granted. refusals() refuses a permission the
 * registry does not know or the list names twice with validation_failed. plan() sets the role's
 * permissions and moves each of its grants, or plans nothing for a list equal to the role's,
 * which the kernel rejects as changing nothing.
 *
 * @implements WriteAction<SetRolePermissions, SetRolePermissionsAggregates>
 * @implements RefusesCommand<SetRolePermissions, SetRolePermissionsAggregates>
 */
#[Action(handles: SetRolePermissions::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Cli])]
#[Internal]
final readonly class SetRolePermissionsAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private GrantReader $grants,
        private PermissionCatalog $catalog,
    ) {}

    /**
     * @param  SetRolePermissions  $command
     */
    #[Override]
    public function resolve(Command $command): SetRolePermissionsAggregates
    {
        $stored = $this->grants->role($command->role);
        $grants = $stored instanceof StoredRole ? $this->grants->roleGrants($command->role) : null;

        return new SetRolePermissionsAggregates(
            $command->role,
            $stored,
            $grants,
            RoleCommandRefusals::known($this->catalog, $command->permissions),
            RoleCommandRefusals::unknown($this->catalog, $command->permissions),
            $grants instanceof RoleGrants ? $this->grants->actorGrants(array_map(static fn (StoredGrant $grant): ActorId => $grant->actor, $grants->grants)) : [],
        );
    }

    /**
     * @param  SetRolePermissions  $command
     * @param  SetRolePermissionsAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        return RoleCommandRefusals::of($command->permissions, $aggregates->unknown);
    }

    /**
     * @param  SetRolePermissions  $command
     * @param  SetRolePermissionsAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if ($aggregates->unchanged() || ! $aggregates->grants instanceof RoleGrants) {
            return new Plan;
        }

        return new Plan(
            new RolePermissionsSet($command->role, $command->permissions),
            ...array_map(static fn (StoredGrant $grant): GrantRoleContentChanged => new GrantRoleContentChanged($grant->id), $aggregates->grants->grants),
        );
    }
}
