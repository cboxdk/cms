<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Domain\Dto\CreateRoleAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapGrantAggregates;
use Override;

/**
 * The write action of access.bootstrap (PRD 5.10, GUARDRAILS 2.1), a composite command that calls
 * no other write action through the pipeline: it composes role.create's and grant.assign's resolve,
 * refusals and plans into one, so the bootstrap role and its grant are one changeset. resolve()
 * reads whether a role has the bootstrap role's id through the GrantReader and, when none has,
 * what role.create reads; then what grant.assign reads. refusals() are role.create's, for a role it
 * creates, and grant.assign's, with the role as the plan leaves it, and validation_failed at role
 * for a role with the id whose ceiling or permissions are not the command's. plan() is the plan of
 * role.create, when it creates the role, followed by the plan of grant.assign, so the commit writes
 * the role before the grant that names it.
 *
 * @implements WriteAction<GrantBootstrapRole, BootstrapGrantAggregates>
 * @implements RefusesCommand<GrantBootstrapRole, BootstrapGrantAggregates>
 */
#[Action(handles: GrantBootstrapRole::class, surfaces: [])]
#[Internal]
final readonly class GrantBootstrapRoleAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private GrantReader $grants,
        private CreateRoleAction $roles,
        private AssignGrantAction $assignments,
    ) {}

    /**
     * @param  GrantBootstrapRole  $command
     */
    #[Override]
    public function resolve(Command $command): BootstrapGrantAggregates
    {
        $stored = $this->grants->role($command->role);
        $creation = $stored instanceof StoredRole ? null : $this->roles->resolve($command->creation());

        return new BootstrapGrantAggregates(
            $creation,
            $this->assignments->resolve($command->assignment()),
            $stored ?? new StoredRole($command->role, $command->ceiling, $command->permissions, AggregateVersion::first()),
        );
    }

    /**
     * @param  GrantBootstrapRole  $command
     * @param  BootstrapGrantAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $refusals = [];

        if ($aggregates->creation instanceof CreateRoleAggregates) {
            array_push($refusals, ...$this->roles->refusals($command->creation(), $aggregates->creation));
        } elseif (! $this->isBootstrapRole($command, $aggregates->role)) {
            $refusals[] = new CatalogError(ErrorCode::ValidationFailed, new FieldPath('role'), sprintf(
                'The role %s exists and is not the bootstrap role: its ceiling is not %s or it lacks a permission of the command.',
                $command->role->toString(),
                $command->ceiling->value,
            ));
        }

        return [...$refusals, ...$this->assignments->refusals($command->assignment(), $aggregates->planned())];
    }

    /**
     * @param  GrantBootstrapRole  $command
     * @param  BootstrapGrantAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $grant = $this->assignments->plan($command->assignment(), $aggregates->planned());

        if (! $aggregates->creation instanceof CreateRoleAggregates) {
            return new Plan($grant);
        }

        return new Plan($this->roles->plan($command->creation(), $aggregates->creation), $grant);
    }

    /**
     * Whether the role with the id has the command's ceiling and every one of its permissions.
     */
    private function isBootstrapRole(GrantBootstrapRole $command, StoredRole $role): bool
    {
        if ($role->ceiling !== $command->ceiling) {
            return false;
        }

        $held = array_map(static fn (CommandName $permission): string => $permission->value, $role->permissions);

        return array_all($command->permissions, static fn (CommandName $permission): bool => in_array($permission->value, $held, true));
    }
}
