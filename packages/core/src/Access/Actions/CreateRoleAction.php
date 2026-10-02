<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\RoleCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Dto\CreateRoleAggregates;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Access\Domain\RoleHandleRef;
use Override;

/**
 * The write action of role.create (PRD 5.10, 6.2). resolve() reads whether a role has the id or
 * the handle through the GrantReader, and which permissions the registry knows through the
 * PermissionCatalog. The kernel's authorize step holds the issuing actor to role.create on some
 * node and the role's ceiling to its classification access. refusals() refuses a handle another
 * role has and a permission the registry does not know or the list names twice, each with
 * validation_failed, and plan() creates the role.
 *
 * @implements WriteAction<CreateRole, CreateRoleAggregates>
 * @implements RefusesCommand<CreateRole, CreateRoleAggregates>
 */
#[Action(handles: CreateRole::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Cli])]
#[Internal]
final readonly class CreateRoleAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private GrantReader $grants,
        private PermissionCatalog $catalog,
    ) {}

    /**
     * @param  CreateRole  $command
     */
    #[Override]
    public function resolve(Command $command): CreateRoleAggregates
    {
        return new CreateRoleAggregates(
            $command->role,
            $this->grants->role($command->role)?->version,
            new RoleHandleRef($command->handle),
            $this->grants->handleTaken($command->handle),
            $command->ceiling,
            RoleCommandRefusals::known($this->catalog, $command->permissions),
            RoleCommandRefusals::unknown($this->catalog, $command->permissions),
        );
    }

    /**
     * @param  CreateRole  $command
     * @param  CreateRoleAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $refusals = [];

        if ($aggregates->handleTaken) {
            $refusals[] = new CatalogError(ErrorCode::ValidationFailed, new FieldPath('handle'), sprintf(
                'Another role has the handle %s; a handle names one role.',
                $command->handle->value,
            ));
        }

        return [...$refusals, ...RoleCommandRefusals::of($command->permissions, $aggregates->unknown)];
    }

    /**
     * @param  CreateRole  $command
     * @param  CreateRoleAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new RoleCreated($command->role, $command->handle, $command->ceiling, $command->permissions));
    }
}
