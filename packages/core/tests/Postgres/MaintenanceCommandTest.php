<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Tests\Identity\RegistrationWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Support\Facades\DB;

/*
 * Maintenance commands on Postgres (PRD 5.16, 6.5 invariant 37): after the genesis, the installation
 * operator registers and activates the first staff member through the real pipeline, commit and
 * writers, with the MaintenanceAuthorizer and the operator read from `installation`. Each commits a
 * changeset of the maintenance issuer by the operator; a rerun of the same unit of work replays the
 * first receipt and commits nothing. Invariant 37 holds for every actor but the genesis: a command
 * whose actor is the pending staff member is refused with actor_not_active.
 */

const MAINTAINED_STAFF = '019cd79e-4600-7000-8000-000000000b01';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

function maintainedWorld(): RegistrationWorld
{
    $world = new RegistrationWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));

    return $world;
}

/**
 * @param  list<CatalogError>  $errors
 * @return list<string>
 */
function maintainedErrors(array $errors): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $errors);
}

it('registers and activates the first staff member as the operator, and a rerun of the same unit replays', function (): void {
    $world = maintainedWorld();
    $operator = $world->install();
    $maintenance = $world->maintenance();
    $register = new MaintenanceCall(
        new RegisterActor(ActorId::fromString(MAINTAINED_STAFF), ActorClass::Staff, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')),
        new UnitOfWork('staff:'.hash('sha256', 'mette@example.com')),
    );

    $registered = $maintenance->run($register);
    $again = $maintenance->run($register);
    $activated = $maintenance->run(new MaintenanceCall(new ActivateActor(ActorId::fromString(MAINTAINED_STAFF), new AggregateVersion(1)), new UnitOfWork('staff:activate:'.MAINTAINED_STAFF)));
    $superuser = StorageTables::superuser();
    $changesets = $superuser->table('changesets')->orderBy('changeset_id')->get(['command', 'actor_id', 'surface', 'issuer_kind'])
        ->map(static fn (object $row): string => implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', (array) $row)))
        ->all();

    expect(maintainedErrors($registered->errors))->toBe([])
        ->and($registered->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($registered->receipt->changesetId?->toString())
        ->and($activated->outcome())->toBe(Outcome::Committed)
        ->and($changesets)->toBe([
            "installation.genesis {$operator->toString()} maintenance system",
            "actor.register {$operator->toString()} maintenance system",
            "actor.activate {$operator->toString()} maintenance system",
        ])
        ->and($superuser->table('actors')->where('id', MAINTAINED_STAFF)->value('state'))->toBe('active');
});

it('still refuses a command whose actor is pending with actor_not_active', function (): void {
    $world = maintainedWorld();
    $world->install();
    $world->maintenance()->run(new MaintenanceCall(
        new RegisterActor(ActorId::fromString(MAINTAINED_STAFF), ActorClass::Staff, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')),
        new UnitOfWork('staff:mette'),
    ));

    $result = $world->run(ActorId::fromString(MAINTAINED_STAFF), new ActivateActor(ActorId::fromString(MAINTAINED_STAFF), new AggregateVersion(1)), 'pending-self-activation');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(maintainedErrors($result->errors))->toBe(['actor_not_active'])
        ->and(StorageTables::superuser()->table('actors')->where('id', MAINTAINED_STAFF)->value('state'))->toBe('pending');
});

it('refuses the operator outside the maintenance pipeline, where it holds no grant', function (): void {
    $world = maintainedWorld();
    $operator = $world->install();

    $result = $world->run(
        $operator,
        new RegisterActor(ActorId::fromString(MAINTAINED_STAFF), ActorClass::Staff, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')),
        'operator-outside-maintenance',
        app(CommandAuthorizer::class),
    );

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(maintainedErrors($result->errors))->toBe(['unauthorized']);
});
