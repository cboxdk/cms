<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use Cbox\Cms\Contracts\Plans\Mutations\RoleCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapOutcome;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapRequest;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapSettings;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use Cbox\Cms\Core\Tests\Maintenance\BootstrapActionWorld;

/*
 * The one-time access bootstrap (PRD 5.10, 5.16) called directly with its DTO and the fakes of the
 * ports it reads (GUARDRAILS 9). It creates the bootstrap role with every command and query of the
 * registry and the ceiling sensitive, and grants it to the staff actor on the node, both as the
 * installation operator through the maintenance pipeline, as one access.bootstrap changeset, so a
 * grant that cannot commit leaves no role. It is refused in production, without an
 * operator, once a staff member holds a grant, for a node that does not exist, an actor that is
 * not an active staff actor and a role with the handle that is not the bootstrap role; it uses the
 * bootstrap role when it exists, and commits nothing when the role's creation is refused.
 */

function bootstrapRun(BootstrapActionWorld $world, ?BootstrapRequest $request = null): BootstrapOutcome
{
    return $world->action()->run($request ?? new BootstrapRequest($world->staff, $world->node));
}

/**
 * @return list<string> each error as "<code> <path>"
 */
function bootstrapRefusals(BootstrapOutcome $outcome): array
{
    return array_map(
        static fn (CatalogError $error): string => trim($error->code->value.' '.($error->path instanceof FieldPath ? $error->path->toString() : '')),
        $outcome->errors(),
    );
}

it('creates the bootstrap role and grants it to the staff actor on the node, as the operator in one changeset', function (): void {
    $world = new BootstrapActionWorld;

    $outcome = bootstrapRun($world);
    $command = $world->committer->pending[0]->input;
    $mutations = $world->committer->pending[0]->plan->mutations();

    expect($outcome->done())->toBeTrue()
        ->and($outcome->errors())->toBe([])
        ->and($outcome->createsRole)->toBeTrue()
        ->and($world->changesets())->toBe(['access.bootstrap '.$world->operator->toString()])
        ->and($world->committer->pending[0]->envelope->surface)->toBe(IssuingSurface::Maintenance)
        ->and($command)->toBeInstanceOf(GrantBootstrapRole::class)
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $mutations))->toBe([RoleCreated::class, GrantAssigned::class]);

    assert($command instanceof GrantBootstrapRole && $mutations[0] instanceof RoleCreated && $mutations[1] instanceof GrantAssigned);
    [$create, $assign] = $mutations;

    expect([$create->handle->value, $create->ceiling])->toBe(['administrator', ClassificationAccess::Sensitive])
        ->and(array_map(static fn (CommandName $name): string => $name->value, $create->permissions))->toBe(['entry.create', 'grant.assign', 'path.resolve', 'role.create'])
        ->and($assign->role->equals($create->role))->toBeTrue()
        ->and($assign->actor->equals($world->staff))->toBeTrue()
        ->and($assign->node->equals($world->node))->toBeTrue()
        ->and([$assign->effect, $assign->locales])->toBe([GrantEffect::Allow, null])
        ->and($outcome->role?->equals($create->role))->toBeTrue()
        ->and($outcome->grant?->equals($assign->grant))->toBeTrue()
        ->and($command->grant->equals($assign->grant))->toBeTrue();
});

it('leaves no role when the grant cannot commit, because the role and its grant are one changeset', function (): void {
    $world = new BootstrapActionWorld;
    $world->committer->at($world->staff, new AggregateVersion(9));

    $outcome = bootstrapRun($world);
    $mutations = $world->committer->pending[0]->plan->mutations();

    expect($outcome->done())->toBeFalse()
        ->and(bootstrapRefusals($outcome))->toBe(['version_conflict'])
        ->and($world->changesets())->toBe(['access.bootstrap '.$world->operator->toString()])
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $mutations))->toBe([RoleCreated::class, GrantAssigned::class]);
});

it('refuses in the production environment before it reads anything', function (): void {
    $world = new BootstrapActionWorld;
    $world->settings = new BootstrapSettings(new RoleHandle('administrator'), true);

    $outcome = bootstrapRun($world);

    expect(bootstrapRefusals($outcome))->toBe(['access_bootstrap_production'])
        ->and($world->state->reads)->toBe(0)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses without an installation operator', function (): void {
    $world = new BootstrapActionWorld;
    $world->installation->operator = null;

    expect(bootstrapRefusals(bootstrapRun($world)))->toBe(['installation_operator_missing'])
        ->and($world->committer->pending)->toBe([]);
});

it('refuses once a staff member holds a grant', function (): void {
    $world = new BootstrapActionWorld;
    $world->state->staffGranted = true;

    expect(bootstrapRefusals(bootstrapRun($world)))->toBe(['access_bootstrap_done'])
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a node that does not exist, an actor that is not a staff actor and a staff actor that is not active', function (): void {
    $world = new BootstrapActionWorld;
    $service = $world->identity->addActor(ActorClass::Service)->id;
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;

    $noNode = bootstrapRun($world, new BootstrapRequest($world->staff, NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000024f1')));
    $notStaff = bootstrapRun($world, new BootstrapRequest($service, $world->node));
    $notActive = bootstrapRun($world, new BootstrapRequest($pending, $world->node));

    expect(bootstrapRefusals($noNode))->toBe(['validation_failed node'])
        ->and(bootstrapRefusals($notStaff))->toBe(['validation_failed actor'])
        ->and(bootstrapRefusals($notActive))->toBe(['actor_not_active actor'])
        ->and($world->committer->pending)->toBe([]);
});

it('uses the bootstrap role when a role with the handle is it, and grants it without creating one', function (): void {
    $world = new BootstrapActionWorld;
    $role = RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000024f2');
    $permissions = array_map(static fn (string $name): CommandName => new CommandName($name), ['entry.create', 'grant.assign', 'path.resolve', 'role.create', 'role.set_permissions']);
    $world->state->roles['administrator'] = new ExistingRole($role, ClassificationAccess::Sensitive, $permissions);
    $world->reader->addRole(new StoredRole($role, ClassificationAccess::Sensitive, $permissions, AggregateVersion::first()), new RoleHandle('administrator'));

    $outcome = bootstrapRun($world);

    expect($outcome->done())->toBeTrue()
        ->and($outcome->createsRole)->toBeFalse()
        ->and($world->changesets())->toBe(['access.bootstrap '.$world->operator->toString()])
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $world->committer->pending[0]->plan->mutations()))->toBe([GrantAssigned::class])
        ->and($outcome->role?->equals($role))->toBeTrue();
});

/**
 * @param  list<string>  $names
 * @return list<CommandName>
 */
function bootstrapNames(array $names): array
{
    return array_map(static fn (string $name): CommandName => new CommandName($name), $names);
}

it('refuses a role with the handle that is not the bootstrap role', function (ClassificationAccess $ceiling, array $names): void {
    /** @var list<string> $names */
    $world = new BootstrapActionWorld;
    $world->state->roles['administrator'] = new ExistingRole(
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000024f3'),
        $ceiling,
        bootstrapNames($names),
    );

    expect(bootstrapRefusals(bootstrapRun($world)))->toBe(['access_bootstrap_role_conflict role'])
        ->and($world->committer->pending)->toBe([]);
})->with([
    'a lower ceiling' => [ClassificationAccess::Personal, ['entry.create', 'grant.assign', 'path.resolve', 'role.create']],
    'a permission missing' => [ClassificationAccess::Sensitive, ['entry.create', 'grant.assign', 'role.create']],
]);

it('commits neither the role nor the grant when the role\'s creation is refused', function (): void {
    $world = new BootstrapActionWorld;
    $world->reader->addRole(new StoredRole(RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000024f4'), ClassificationAccess::Public, [], AggregateVersion::first()), new RoleHandle('administrator'));

    $outcome = bootstrapRun($world);

    expect($outcome->done())->toBeFalse()
        ->and(bootstrapRefusals($outcome))->toBe(['validation_failed handle'])
        ->and($outcome->result?->outcome()->isCommitted())->toBeFalse()
        ->and($world->committer->pending)->toBe([]);
});
