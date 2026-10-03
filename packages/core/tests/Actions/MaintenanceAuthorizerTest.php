<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Dto\RegisterActorAggregates;
use Cbox\Cms\Core\Maintenance\Domain\MaintenanceAuthorizer;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Dto\RegisterSiteAggregates;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;

/*
 * The maintenance pipeline's authorization (PRD 5.16, 6.2 phase 2): the kernel's command pipeline
 * with the MaintenanceAuthorizer, over the fakes of the actor commands (GUARDRAILS 9). A listed
 * command by the installation operator on an envelope of the maintenance issuer runs; an unlisted
 * command, another actor or another issuer is refused as unauthorized and commits nothing. Invariant
 * 37 still holds for every actor but the genesis: a command whose actor is pending is refused with
 * actor_not_active before it is authorized. role.create and grant.assign run only through the access
 * bootstrap's authorizer, which runs nothing else.
 */

const MAINTAINED_ACTOR = '01936f5e-8a2b-7c3d-9e4f-000000000501';

const BOOTSTRAP_GRANT = '01936f5e-8a2b-7c3d-9e4f-000000000502';

const BOOTSTRAP_ROLE = '01936f5e-8a2b-7c3d-9e4f-000000000503';

const BOOTSTRAP_NODE = '01936f5e-8a2b-7c3d-9e4f-000000000504';

/**
 * The actor command fakes with an active service operator, named in the installation, and the
 * MaintenanceAuthorizer.
 *
 * @return array{ActorCommandFakes, ActorId}
 */
function maintenanceWorld(): array
{
    $world = new ActorCommandFakes;
    $operator = $world->identity->addActor(ActorClass::Service)->id;
    $world->authorizer = new MaintenanceAuthorizer(new FakeInstallationOperator($operator));

    return [$world, $operator];
}

function maintenanceEnvelope(ActorId $actor, string $unit = 'staff:test', IssuingSurface $surface = IssuingSurface::Maintenance): Envelope
{
    return Envelope::internal($surface, $surface === IssuingSurface::Maintenance ? EnvelopeIssuer::System : EnvelopeIssuer::Seed, $actor, new UnitOfWork($unit), new CorrelationId('maintenance-test'));
}

function maintenanceAccess(ActorId $actor): AccessContext
{
    return new AccessContext(new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Public);
}

function maintainedStaff(): RegisterActor
{
    return new RegisterActor(ActorId::fromString(MAINTAINED_ACTOR), ActorClass::Staff, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com'));
}

it('allows a listed command by the operator on an envelope of the maintenance issuer', function (): void {
    [$world, $operator] = maintenanceWorld();

    $result = $world->run(maintainedStaff(), envelope: maintenanceEnvelope($operator), access: maintenanceAccess($operator));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('actor.register')
        ->and($world->committer->pending[0]->envelope->surface)->toBe(IssuingSurface::Maintenance)
        ->and($world->committer->pending[0]->envelope->issuerKind)->toBe(EnvelopeIssuer::System)
        ->and($world->committer->pending[0]->envelope->actor->equals($operator))->toBeTrue();
});

it('allows site.register to the operator on an envelope of the maintenance issuer, and to no one else', function (): void {
    [$world, $operator] = maintenanceWorld();
    $authorizer = new MaintenanceAuthorizer(new FakeInstallationOperator($operator));
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000521');
    $register = new RegisterSite($site, new SiteHandle('north'), NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000522'), [new Locale('da'), new Locale('en')]);
    $aggregates = new RegisterSiteAggregates($site, null, new SiteHandleRef(new SiteHandle('north')), null);

    $allowed = $authorizer->authorize(maintenanceAccess($operator), new CommandName('site.register'), $register, $aggregates, maintenanceEnvelope($operator, 'sites:north'));
    $asAdmin = $authorizer->authorize(maintenanceAccess($world->admin), new CommandName('site.register'), $register, $aggregates, maintenanceEnvelope($world->admin, 'sites:north'));

    expect($allowed->allowed())->toBeTrue()
        ->and($asAdmin->allowed())->toBeFalse();
});

it('refuses a command that is not on its list, also by the operator on a maintenance envelope', function (): void {
    [$world, $operator] = maintenanceWorld();
    $staff = $world->identity->addActor(ActorClass::Staff)->id;

    $result = $world->run(new DeactivateActor($staff), envelope: maintenanceEnvelope($operator), access: maintenanceAccess($operator));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toBe('A maintenance command runs only actor.activate, actor.register, site.register, not actor.deactivate.')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses another actor than the operator, as principal or as the envelope\'s actor', function (): void {
    [$world, $operator] = maintenanceWorld();

    $asAdmin = $world->run(maintainedStaff(), envelope: maintenanceEnvelope($world->admin), access: maintenanceAccess($world->admin));

    expect($asAdmin->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($asAdmin))->toBe(['unauthorized'])
        ->and($asAdmin->errors[0]->message)->toBe(sprintf('A maintenance command runs only as the installation operator %s, acting on behalf of no one.', $operator->toString()))
        ->and($world->committer->pending)->toBe([]);

    $authorizer = new MaintenanceAuthorizer(new FakeInstallationOperator($operator));
    $mixed = $authorizer->authorize(maintenanceAccess($operator), new CommandName('actor.register'), maintainedStaff(), new RegisterActorAggregates(ActorId::fromString(MAINTAINED_ACTOR), null, null, null), maintenanceEnvelope($world->admin));
    $delegated = $authorizer->authorize(
        new AccessContext(new ActorPrincipal($operator, [$world->admin], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Public),
        new CommandName('actor.register'),
        maintainedStaff(),
        new RegisterActorAggregates(ActorId::fromString(MAINTAINED_ACTOR), null, null, null),
        maintenanceEnvelope($operator),
    );

    expect($mixed->allowed())->toBeFalse()
        ->and($delegated->allowed())->toBeFalse();
});

it('refuses the operator on an envelope of another issuer', function (IssuingSurface $surface): void {
    [$world, $operator] = maintenanceWorld();
    $envelope = $surface === IssuingSurface::Cli
        ? Envelope::external(IssuingSurface::Cli, EnvelopeIssuer::System, $operator, new IdempotencyKey('cli-1'), new CorrelationId('cli'))
        : maintenanceEnvelope($operator, surface: $surface);

    $result = $world->run(maintainedStaff(), envelope: $envelope, access: maintenanceAccess($operator));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toBe(sprintf('actor.register runs as the installation operator only from the maintenance issuer, not from %s.', $surface->value))
        ->and($world->committer->pending)->toBe([]);
})->with(['the cli' => [IssuingSurface::Cli], 'a seed' => [IssuingSurface::Seed]]);

it('refuses everything while the installation has no operator', function (): void {
    [$world, $operator] = maintenanceWorld();
    $world->authorizer = new MaintenanceAuthorizer(new FakeInstallationOperator);

    $result = $world->run(maintainedStaff(), envelope: maintenanceEnvelope($operator), access: maintenanceAccess($operator));

    expect(ActorCommandFakes::errors($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toBe('The installation has no operator. Run cms:install in the maintenance process first.');
});

it('still refuses a command whose actor is pending with actor_not_active, the genesis being the only exception', function (): void {
    [$world, $operator] = maintenanceWorld();
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;
    $world->authorizer = new FakeCommandAuthorizer;

    $result = $world->run(new ActivateActor($pending, new AggregateVersion(1)), envelope: maintenanceEnvelope($pending), access: maintenanceAccess($pending));
    $world->authorizer = new MaintenanceAuthorizer(new FakeInstallationOperator($operator));
    $asOperator = $world->run(new ActivateActor($pending, new AggregateVersion(1)), envelope: maintenanceEnvelope($operator), access: maintenanceAccess($operator));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['actor_not_active'])
        ->and($asOperator->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending)->toHaveCount(1);
});

it('allows access.bootstrap only to the access bootstrap\'s authorizer, which allows nothing else, role.create and grant.assign included', function (): void {
    [$world, $operator] = maintenanceWorld();
    $installation = new FakeInstallationOperator($operator);
    $aggregates = new RegisterActorAggregates(ActorId::fromString(MAINTAINED_ACTOR), null, null, null);
    $grant = new AssignGrant(GrantId::fromString(BOOTSTRAP_GRANT), ActorId::fromString(MAINTAINED_ACTOR), RoleId::fromString(BOOTSTRAP_ROLE), NodeId::fromString(BOOTSTRAP_NODE), GrantEffect::Allow);
    $setup = new MaintenanceAuthorizer($installation);
    $bootstrap = MaintenanceAuthorizer::forAccessBootstrap($installation);
    $decide = static fn (MaintenanceAuthorizer $authorizer, string $command): bool => $authorizer->authorize(
        maintenanceAccess($operator),
        new CommandName($command),
        $command === 'grant.assign' ? $grant : maintainedStaff(),
        $aggregates,
        maintenanceEnvelope($operator, 'access-bootstrap:test'),
    )->allowed();

    expect(array_map(static fn (string $command): bool => $decide($setup, $command), ['actor.register', 'access.bootstrap', 'role.create', 'grant.assign']))->toBe([true, false, false, false])
        ->and(array_map(static fn (string $command): bool => $decide($bootstrap, $command), ['actor.register', 'site.register', 'role.create', 'grant.assign', 'access.bootstrap']))->toBe([false, false, false, false, true])
        ->and($setup->authorize(maintenanceAccess($operator), new CommandName('access.bootstrap'), $grant, $aggregates, maintenanceEnvelope($operator))->reason)
        ->toBe('A maintenance command runs only actor.activate, actor.register, site.register, not access.bootstrap.')
        ->and($bootstrap->authorize(maintenanceAccess($operator), new CommandName('access.bootstrap'), $grant, $aggregates, maintenanceEnvelope($world->admin))->allowed())->toBeFalse();
});
