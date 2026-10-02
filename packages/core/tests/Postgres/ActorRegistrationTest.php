<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Identity\Adapter\ActorActivatedWriter;
use Cbox\Cms\Core\Identity\Adapter\ActorRegisteredWriter;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Identity\RegistrationWorld;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * actor.register and actor.activate on Postgres (PRD 5.16, 6.4, 12.2): the real pipeline, commit
 * and writers as the app role. A registration commits one changeset that creates the actor pending
 * at version 1 with its profile, with its audit row, the event actor.registered and the receipt; an
 * activation commits one more that makes it active at version 2. The app role writes neither
 * `actors` nor `actor_profiles` itself, and the owner-role functions that do refuse to run outside
 * the transaction of their command's changeset. An actor reads its own profile, and another's only
 * through a role whose permissions name actor.list.
 */

const REGISTRATION_ACTOR = '019cd79e-4600-7000-8000-000000000a01';

const REGISTRATION_SERVICE = '019cd79e-4600-7000-8000-000000000a02';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{RegistrationWorld, PostgresIdentity, PostgresAccessFixtures}
 */
function registrationWorld(): array
{
    AccessWorld::seed();
    $world = new RegistrationWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));

    return [
        $world,
        PostgresIdentity::at($world->clock),
        new PostgresAccessFixtures(app(DatabaseManager::class), $world->clock, new FakeIdGenerator(seed: 4141, clock: $world->clock)),
    ];
}

function registerCommand(string $id, ActorClass $class = ActorClass::Staff, ?ActorId $responsible = null, string $email = 'mette@example.com'): RegisterActor
{
    return new RegisterActor(ActorId::fromString($id), $class, new DisplayName('Mette Holm'), new EmailAddress($email), $responsible);
}

function registeredChangeset(WriteResult $result): string
{
    return $result->receipt->changesetId instanceof ChangesetId
        ? $result->receipt->changesetId->toString()
        : throw new AssertionFailedError('The call committed no changeset: '.implode(', ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
}

/**
 * The actor's row as the superuser reads it: "<class> <state> <version> <generation> <responsible>".
 */
function registeredRow(string $actor): string
{
    return StorageTables::texts(StorageTables::superuser(), <<<'SQL'
        select concat_ws(' ', actor_class, state, version, credential_generation, coalesce(responsible_actor_id::text, '-')) as value
        from actors where id = ?
        SQL, [$actor])[0];
}

/**
 * The actors whose profiles the context reads, sorted.
 *
 * @return list<string>
 */
function readableProfiles(AccessContext $context): array
{
    return DB::transaction(static function () use ($context): array {
        new ActorContext(app(ConnectionResolverInterface::class))->set($context);

        return StorageTables::texts(DB::connection(), 'select actor_id::text as value from actor_profiles order by actor_id');
    });
}

function profileReader(ActorId $actor, ClassificationAccess $access = ClassificationAccess::Personal): AccessContext
{
    return new AccessContext(new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], $access);
}

it('registers an actor pending and activates it, one changeset each with its audit row and event', function (): void {
    [$world, $identity] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;

    $registered = $world->run($admin, registerCommand(REGISTRATION_ACTOR), 'register-1');
    $registeredAt = registeredRow(REGISTRATION_ACTOR);
    $activated = $world->run($admin, new ActivateActor(ActorId::fromString(REGISTRATION_ACTOR), new AggregateVersion(1)), 'activate-1');
    $changesets = [registeredChangeset($registered), registeredChangeset($activated)];
    $superuser = StorageTables::superuser();

    expect($registered->outcome())->toBe(Outcome::Committed)
        ->and($activated->outcome())->toBe(Outcome::Committed)
        ->and($registeredAt)->toBe('staff pending 1 1 -')
        ->and(registeredRow(REGISTRATION_ACTOR))->toBe('staff active 2 1 -')
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', display_name, email, version) as value from actor_profiles where actor_id = ?', [REGISTRATION_ACTOR]))
        ->toBe(['Mette Holm mette@example.com 1'])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', command, actor_id) as value from changesets where changeset_id = any(?::uuid[]) order by changeset_id', ['{'.implode(',', $changesets).'}']))
        ->toBe(['actor.register '.$admin->toString(), 'actor.activate '.$admin->toString()])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', command, array_to_string(aggregates, \',\')) as value from audit where changeset_id = any(?::uuid[]) order by changeset_id', ['{'.implode(',', $changesets).'}']))
        ->toBe(['actor.register actor:'.REGISTRATION_ACTOR, 'actor.activate actor:'.REGISTRATION_ACTOR])
        ->and(StorageTables::texts($superuser, <<<'SQL'
            select concat_ws(' ', aggregate_id, aggregate_version, type, type_version, data::text) as value
            from events where changeset_id = any(?::uuid[]) order by event_id
            SQL, ['{'.implode(',', $changesets).'}']))
        ->toBe([
            sprintf('%1$s 1 actor.registered 1 {"actor": {"identifier": "%1$s"}, "class": {"enum": "staff"}, "responsible": {"null": null}, "credential_generation": {"integer": 1}}', REGISTRATION_ACTOR),
            sprintf('%1$s 2 actor.activated 1 {"actor": {"identifier": "%1$s"}}', REGISTRATION_ACTOR),
        ]);

    $actor = $identity->directory()->find(ActorId::fromString(REGISTRATION_ACTOR));

    expect($actor)->toBeInstanceOf(Actor::class)
        ->and($actor?->state)->toBe(ActorState::Active)
        ->and($actor?->version)->toBe(2);
});

it('registers a service actor with the staff actor responsible for it', function (): void {
    [$world, $identity] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;

    $result = $world->run($admin, registerCommand(REGISTRATION_SERVICE, ActorClass::Service, $admin, 'ops@example.com'), 'register-service');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(registeredRow(REGISTRATION_SERVICE))->toBe('service pending 1 1 '.$admin->toString());
});

it('rejects a second registration of the id and a second activation, and changes nothing', function (): void {
    [$world, $identity] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $world->run($admin, registerCommand(REGISTRATION_ACTOR), 'register-1');
    $world->run($admin, new ActivateActor(ActorId::fromString(REGISTRATION_ACTOR), new AggregateVersion(1)), 'activate-1');

    $again = $world->run($admin, registerCommand(REGISTRATION_ACTOR, email: 'other@example.com'), 'register-2');
    $activeAgain = $world->run($admin, new ActivateActor(ActorId::fromString(REGISTRATION_ACTOR), new AggregateVersion(2)), 'activate-2');

    expect(array_map(static fn (CatalogError $error): string => $error->code->value, $again->errors))->toBe(['version_conflict'])
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value, $activeAgain->errors))->toBe(['validation_failed'])
        ->and(registeredRow(REGISTRATION_ACTOR))->toBe('staff active 2 1 -')
        ->and(StorageTables::superuser()->table('actor_profiles')->where('actor_id', REGISTRATION_ACTOR)->value('email'))->toBe('mette@example.com');
});

it('refuses the app role a direct write of actors and actor_profiles', function (): void {
    [, $identity] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $context = profileReader($admin);
    $write = static fn (string $sql, array $bindings): mixed => DB::transaction(static function () use ($context, $sql, $bindings): bool {
        new ActorContext(app(ConnectionResolverInterface::class))->set($context);

        return DB::insert($sql, $bindings);
    });

    expect(StorageTables::sqlState(static fn (): mixed => $write(
        "insert into actors (id, actor_class, state, version, credential_generation, created_at) values (?, 'staff', 'active', 1, 1, now())",
        [REGISTRATION_ACTOR],
    )))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $write(
            "insert into actor_profiles (actor_id, display_name, email, version) values (?, 'Mette Holm', 'mette@example.com', 1)",
            [$admin->toString()],
        )))->toBe('42501');
});

it('lets an actor read its own profile, and another\'s only through a role that names actor.list', function (): void {
    [$world, $identity, $fixtures] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $world->run($admin, registerCommand(REGISTRATION_ACTOR), 'register-1');
    $world->run($admin, registerCommand(REGISTRATION_SERVICE, ActorClass::Service, $admin, 'ops@example.com'), 'register-service');
    $self = ActorId::fromString(REGISTRATION_ACTOR);
    $root = NodeId::fromString(AccessWorld::ROOT);

    $own = readableProfiles(profileReader($self));
    $none = readableProfiles(profileReader($admin));
    $fixtures->grant($admin, $fixtures->role('people', ClassificationAccess::Personal, [new CommandName('actor.list')]), $root);
    $listed = readableProfiles(profileReader($admin));
    $belowPersonal = readableProfiles(profileReader($admin, ClassificationAccess::Confidential));

    expect($own)->toBe([REGISTRATION_ACTOR])
        ->and($none)->toBe([])
        ->and($listed)->toBe([AccessWorld::ALICE, REGISTRATION_ACTOR])
        ->and($belowPersonal)->toBe([])
        ->and(DB::table('actor_profiles')->count())->toBe(0);
});

it('refuses the registration and activation functions outside the transaction of their command\'s changeset', function (): void {
    [, $identity] = registrationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $call = static fn (string $sql, array $bindings): mixed => DB::transaction(static function () use ($admin, $sql, $bindings): mixed {
        DB::statement("select set_config('cbox_cms.principal', 'actor', true), set_config('cbox_cms.actor', ?, true)", [$admin->toString()]);

        return DB::selectOne($sql, $bindings);
    });

    expect(StorageTables::sqlState(static fn (): mixed => $call(ActorRegisteredWriter::REGISTER, [
        REGISTRATION_ACTOR, 1, 'staff', null, 'Mette Holm', 'mette@example.com', '2026-03-10 12:00:00+00', AccessWorld::CHANGESET_ALICE,
    ])))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $call(ActorActivatedWriter::ACTIVATE, [$admin->toString(), 2, AccessWorld::CHANGESET_ALICE])))->toBe('42501')
        ->and(StorageTables::superuser()->table('actors')->where('id', REGISTRATION_ACTOR)->count())->toBe(0);
});
