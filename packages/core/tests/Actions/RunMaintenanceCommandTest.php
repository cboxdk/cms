<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Maintenance\Domain\MaintenanceAuthorizer;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;

/*
 * RunMaintenanceCommand (PRD 5.16, invariant 37) called directly with its DTO and the fakes of the
 * ports it reads (GUARDRAILS 9): it runs the command as the installation operator through the
 * maintenance pipeline, on an envelope of the maintenance issuer with the issuer kind system and
 * the key derived from the unit of work, so a rerun of the same unit replays (the Postgres test
 * MaintenanceCommandTest shows the replay); without an operator it rejects with
 * installation_operator_missing and runs nothing.
 */

const MAINTENANCE_STAFF = '01936f5e-8a2b-7c3d-9e4f-000000000511';

/**
 * @return array{RunMaintenanceCommand, ActorCommandFakes, ?ActorId, FakeAccessContexts}
 */
function maintenanceAction(bool $installed = true): array
{
    $world = new ActorCommandFakes;
    $operator = $installed ? $world->identity->addActor(ActorClass::Service)->id : null;
    $installation = new FakeInstallationOperator($operator);
    $world->authorizer = new MaintenanceAuthorizer($installation);
    $clock = new FakeClock;
    $contexts = new FakeAccessContexts;
    $action = new RunMaintenanceCommand(
        $installation,
        $contexts,
        new FakeIdGenerator(clock: $clock),
        $world->pipeline(new FakeIdempotencyStore($clock)->session(), new FakeReceiptStore($clock)->session()),
    );

    return [$action, $world, $operator, $contexts];
}

function maintenanceRegistration(string $unit = 'staff:mette'): MaintenanceCall
{
    return new MaintenanceCall(
        new RegisterActor(ActorId::fromString(MAINTENANCE_STAFF), ActorClass::Staff, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')),
        new UnitOfWork($unit),
    );
}

it('runs the command as the operator on an envelope of the maintenance issuer keyed by its unit of work', function (): void {
    [$action, $world, $operator, $contexts] = maintenanceAction();

    $result = $action->run(maintenanceRegistration());
    $envelope = $world->committer->pending[0]->envelope;

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($envelope->surface)->toBe(IssuingSurface::Maintenance)
        ->and($envelope->issuerKind)->toBe(EnvelopeIssuer::System)
        ->and($operator instanceof ActorId && $envelope->actor->equals($operator))->toBeTrue()
        ->and($envelope->onBehalfOf->chain)->toBe([])
        ->and($envelope->idempotencyKey->equals(Envelope::deriveKey(IssuingSurface::Maintenance, new UnitOfWork('staff:mette'))))->toBeTrue()
        ->and($contexts->asked)->toHaveCount(1)
        ->and($contexts->asked[0] instanceof ActorPrincipal && $operator instanceof ActorId && $contexts->asked[0]->actor->equals($operator))->toBeTrue();
});

it('derives the key from the unit of work alone, the same for a rerun and another for other work', function (): void {
    [$action, $world] = maintenanceAction();

    $action->run(maintenanceRegistration());
    $action->run(new MaintenanceCall(
        new RegisterActor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000512'), ActorClass::Staff, new DisplayName('Ole Holm'), new EmailAddress('ole@example.com')),
        new UnitOfWork('staff:ole'),
    ));
    [$mette, $ole] = $world->committer->pending;

    expect($mette->envelope->idempotencyKey->equals(Envelope::deriveKey(IssuingSurface::Maintenance, new UnitOfWork('staff:mette'))))->toBeTrue()
        ->and($ole->envelope->idempotencyKey->equals(Envelope::deriveKey(IssuingSurface::Maintenance, new UnitOfWork('staff:ole'))))->toBeTrue()
        ->and($mette->envelope->idempotencyKey->equals($ole->envelope->idempotencyKey))->toBeFalse();
});

it('rejects with installation_operator_missing before cms:install, and runs nothing', function (): void {
    [$action, $world, , $contexts] = maintenanceAction(installed: false);

    $result = $action->run(maintenanceRegistration());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['installation_operator_missing'])
        ->and($world->committer->pending)->toBe([])
        ->and($contexts->asked)->toBe([]);
});
