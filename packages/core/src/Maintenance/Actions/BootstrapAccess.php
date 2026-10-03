<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\BootstrapRole;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapOutcome;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapRequest;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapSettings;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The one-time access bootstrap (PRD 5.10, 5.16): in a fresh installation no staff member holds a
 * grant, and the escalation guard lets an actor give only what it has, so the first staff member
 * gets the bootstrap role from the installation operator, in the maintenance process.
 *
 * It is refused, before any command runs and with nothing committed:
 *
 * - in the production environment, with access_bootstrap_production, because full access in
 *   production comes only from a role that only actors on the emergency access list can assign
 *   (PRD 5.10), which is not built yet;
 * - without an installation operator, with installation_operator_missing;
 * - once any staff actor holds a grant, ended or not, with access_bootstrap_done;
 * - for a node that does not exist, or an actor that is not a staff actor, with validation_failed,
 *   and for a staff actor that is not active, with actor_not_active;
 * - when a role has the handle of cbox-cms.access.bootstrap_role and is not the bootstrap role,
 *   with access_bootstrap_role_conflict.
 *
 * Otherwise it runs access.bootstrap, one command whose plan creates the bootstrap role with
 * role.create's plan, unless the role with the handle is it already, and grants it to the actor on
 * the node with grant.assign's plan, allowing in every locale (GrantBootstrapRoleAction). The role
 * and its grant are one changeset, so a run that stops leaves neither (GUARDRAILS 2.1). It runs as
 * the operator through RunMaintenanceCommand, whose pipeline (CoreServiceProvider) has the
 * MaintenanceAuthorizer built forAccessBootstrap(): it allows access.bootstrap, which no other
 * maintenance command may run, and nothing else. That authorizer replaces the kernel's, so the
 * escalation guard and the step-up refusal of an administrative role do not apply: the operator
 * holds nothing, and the bootstrap runs only in the maintenance process.
 *
 * The unit of work is the same for every run, so two bootstraps that run at once commit at most
 * one: the second claims the same idempotency key, waits for the first and ends in
 * idempotency_conflict, or idempotency_in_flight while the first still runs.
 */
#[Internal]
final readonly class BootstrapAccess
{
    /** The unit of work of the bootstrap's changeset, the same for every run. */
    public const string UNIT = 'access-bootstrap';

    public function __construct(
        private BootstrapSettings $settings,
        private InstallationOperator $installation,
        private AccessContexts $contexts,
        private AccessBootstrapState $state,
        private ActorDirectory $actors,
        private IdGenerator $ids,
        private CompiledRegistry $registry,
        private RunMaintenanceCommand $maintenance,
    ) {}

    public function run(BootstrapRequest $request): BootstrapOutcome
    {
        $handle = $this->settings->role;

        if ($this->settings->production) {
            return BootstrapOutcome::refused($handle, new CatalogError(
                ErrorCode::AccessBootstrapProduction,
                null,
                'The access bootstrap does not run in the production environment: full access in production comes only from a role that only actors on the emergency access list can assign (PRD 5.10).',
            ));
        }

        $operator = $this->installation->find();

        if (! $operator instanceof ActorId) {
            return BootstrapOutcome::refused($handle, new CatalogError(
                ErrorCode::InstallationOperatorMissing,
                null,
                'The installation has no operator, so the access bootstrap cannot run. Run cms:install in the maintenance process first.',
            ));
        }

        $state = $this->state->read(
            $this->contexts->for(new ActorPrincipal($operator, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling())),
            $request->node,
            $handle,
        );

        if ($state->staffGranted) {
            return BootstrapOutcome::refused($handle, new CatalogError(
                ErrorCode::AccessBootstrapDone,
                null,
                'A staff member holds a grant already, so the access bootstrap has done its work. Give further access in the panel.',
            ));
        }

        $refusal = $this->refusal($request, $state->nodeExists);

        if ($refusal instanceof CatalogError) {
            return BootstrapOutcome::refused($handle, $refusal);
        }

        $permissions = BootstrapRole::permissions($this->registry);
        $existing = $state->role;

        if ($existing instanceof ExistingRole && ! BootstrapRole::covers($existing, $permissions)) {
            return BootstrapOutcome::refused($handle, new CatalogError(
                ErrorCode::AccessBootstrapRoleConflict,
                new FieldPath('role'),
                sprintf('The role %s has the handle %s already and is not the bootstrap role: its ceiling is not %s or it lacks a command or query of the registry.', $existing->id->toString(), $handle->value, BootstrapRole::CEILING->value),
            ));
        }

        $role = $existing->id ?? new RoleId($this->ids->next());
        $grant = new GrantId($this->ids->next());
        $result = $this->maintenance->run(new MaintenanceCall(
            new GrantBootstrapRole($grant, $request->actor, $request->node, $role, $handle, BootstrapRole::CEILING, $permissions),
            new UnitOfWork(self::UNIT),
        ));

        return BootstrapOutcome::ran($request, $handle, $role, ! $existing instanceof ExistingRole, $grant, $result);
    }

    /**
     * The node must exist and the actor must be an active staff actor: a service actor's grant
     * would leave the bootstrap open, because only a staff grant ends it.
     */
    private function refusal(BootstrapRequest $request, bool $nodeExists): ?CatalogError
    {
        if (! $nodeExists) {
            return new CatalogError(ErrorCode::ValidationFailed, new FieldPath('node'), sprintf('No node has the id %s.', $request->node->toString()));
        }

        $actor = $this->actors->find($request->actor);

        if (! $actor instanceof Actor || $actor->class !== ActorClass::Staff) {
            return new CatalogError(ErrorCode::ValidationFailed, new FieldPath('actor'), sprintf(
                'The access bootstrap grants its role to a staff actor, and the actor %s %s.',
                $request->actor->toString(),
                $actor instanceof Actor ? sprintf('is a %s actor', $actor->class->value) : 'does not exist',
            ));
        }

        if (! $actor->isActive()) {
            return new CatalogError(ErrorCode::ActorNotActive, new FieldPath('actor'), sprintf(
                'The access bootstrap grants its role to an active staff actor, and the actor %s is %s. Activate it first.',
                $request->actor->toString(),
                $actor->state->value,
            ));
        }

        return null;
    }
}
