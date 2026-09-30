<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Identity\Adapter\ActorDeactivatedWriter;
use Cbox\Cms\Core\Tests\Identity\DeactivationWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * actor.deactivate on Postgres (PRD 5.16, 6.4): the real pipeline, commit and writer as the app
 * role. One changeset deactivates the actor at its next version with its credential generation one
 * higher and the source noted, ends every direct grant of it with the changeset, and writes the
 * audit row, the event actor.deactivated and the receipt. The number of statements does not grow
 * with the number of grants. A second deactivation changes nothing, and the owner-role function
 * that writes it refuses to run outside the transaction of an actor.deactivate changeset.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{DeactivationWorld, PostgresIdentity, PostgresAccessFixtures}
 */
function deactivationWorld(): array
{
    AccessWorld::seed();
    $world = DeactivationWorld::onDefault();
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));
    $identity = PostgresIdentity::at($world->clock);
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $world->clock, new FakeIdGenerator(seed: 3232, clock: $world->clock));

    return [$world, $identity, $fixtures];
}

/**
 * The given number of roles, each granted to the actor on the root node.
 *
 * @param  list<RoleId>  $roles
 */
function grantRoles(PostgresAccessFixtures $fixtures, ActorId $actor, array $roles): void
{
    foreach ($roles as $role) {
        $fixtures->grant($actor, $role, NodeId::fromString(AccessWorld::ROOT));
    }
}

/**
 * The actor's row as the superuser reads it: "<state> <version> <generation> <source>".
 */
function deactivatedRow(ActorId $actor): string
{
    return StorageTables::texts(StorageTables::superuser(), <<<'SQL'
        select concat_ws(' ', state, version, credential_generation, coalesce(deactivation_source, '-')) as value
        from actors where id = ?
        SQL, [$actor->toString()])[0];
}

/**
 * @return list<string>
 */
function deactivationErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

function deactivationChangeset(WriteResult $result): string
{
    return $result->receipt->changesetId instanceof ChangesetId ? $result->receipt->changesetId->toString() : throw new AssertionFailedError('The call committed no changeset.');
}

it('deactivates an actor, ends its grants and emits actor.deactivated in one changeset', function (): void {
    [$world, $identity, $fixtures] = deactivationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $target = $identity->addActor(ActorClass::Staff)->id;
    grantRoles($fixtures, $target, [$fixtures->role('team_one', ClassificationAccess::Internal), $fixtures->role('team_two', ClassificationAccess::Internal)]);
    grantRoles($fixtures, $admin, [$fixtures->role('team_three', ClassificationAccess::Internal)]);

    $result = $world->deactivate($admin, $target, source: DeactivationSource::Inactivity);
    $changeset = deactivationChangeset($result);
    $superuser = StorageTables::superuser();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(deactivatedRow($target))->toBe('deactivated 2 2 inactivity')
        ->and(deactivatedRow($admin))->toBe('active 1 1 -')
        ->and(StorageTables::texts($superuser, 'select coalesce(ended_changeset_id::text, \'-\') as value from grants where actor_id = ? order by id', [$target->toString()]))
        ->toBe([$changeset, $changeset])
        ->and(StorageTables::texts($superuser, 'select coalesce(ended_changeset_id::text, \'-\') as value from grants where actor_id = ?', [$admin->toString()]))
        ->toBe(['-'])
        ->and(StorageTables::texts($superuser, <<<'SQL'
            select concat_ws(' ', aggregate_type, aggregate_id, aggregate_version, type, type_version, data::text) as value
            from events where changeset_id = ?
            SQL, [$changeset]))
        ->toBe([sprintf(
            'actor %s 2 actor.deactivated 1 {"actor": {"identifier": "%s"}, "source": {"enum": "inactivity"}, "grants_ended": {"integer": 2}, "credential_generation": {"integer": 2}}',
            $target->toString(),
            $target->toString(),
        )])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', actor_id, command, command_version, array_to_string(aggregates, \',\')) as value from audit where changeset_id = ?', [$changeset]))
        ->toBe([sprintf('%s actor.deactivate 1 actor:%s', $admin->toString(), $target->toString())]);

    $actor = $identity->directory()->find($target);

    expect($actor)->toBeInstanceOf(Actor::class)
        ->and($actor?->state)->toBe(ActorState::Deactivated)
        ->and($actor?->credentialGeneration->value)->toBe(2);
});

it('compiles no access region for the actor once its grants ended', function (): void {
    [$world, $identity, $fixtures] = deactivationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $target = $identity->addActor(ActorClass::Staff)->id;
    grantRoles($fixtures, $target, [$fixtures->role('team', ClassificationAccess::Internal)]);
    $principal = new ActorPrincipal($target, [], IssuerKind::Service, ClassificationAccess::Sensitive);

    $regions = static fn (): int => DB::transaction(static fn (): int => count(app(AccessResolver::class)->resolve($principal)->regions));
    $before = $regions();
    $world->deactivate($admin, $target);

    expect($before)->toBe(1)
        ->and($regions())->toBe(0);
});

it('grants a role again on the node of an ended grant', function (): void {
    [$world, $identity, $fixtures] = deactivationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $target = $identity->addActor(ActorClass::Staff)->id;
    $desk = $fixtures->role('team', ClassificationAccess::Internal);
    grantRoles($fixtures, $target, [$desk]);
    $world->deactivate($admin, $target);

    grantRoles($fixtures, $target, [$desk]);

    expect(StorageTables::texts(StorageTables::superuser(), 'select coalesce(ended_changeset_id::text, \'-\') as value from grants where actor_id = ? order by ended_changeset_id nulls last', [$target->toString()]))
        ->toHaveCount(2)
        ->and(StorageTables::violation(static fn () => grantRoles($fixtures, $target, [$desk])))
        ->toBe('23505 grants_actor_role_node_key');
});

it('runs the same statements for an actor with 20 grants as for one with 200', function (): void {
    [$world, $identity, $fixtures] = deactivationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $few = $identity->addActor(ActorClass::Staff)->id;
    $many = $identity->addActor(ActorClass::Staff)->id;
    $roles = array_map(static fn (int $index): RoleId => $fixtures->role('team_'.$index, ClassificationAccess::Internal), range(1, 200));
    grantRoles($fixtures, $few, array_slice($roles, 0, 20));
    grantRoles($fixtures, $many, $roles);

    $statements = static function (ActorId $target, string $key) use ($world, $admin): int {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            expect($world->deactivate($admin, $target, $key)->outcome())->toBe(Outcome::Committed);

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
    };

    $forFew = $statements($few, 'deactivate-few');
    $forMany = $statements($many, 'deactivate-many');
    $ended = static fn (ActorId $actor): int => StorageTables::superuser()->table('grants')->where('actor_id', $actor->toString())->whereNotNull('ended_changeset_id')->count();

    expect($forFew)->toBe($forMany)
        ->and($ended($few))->toBe(20)
        ->and($ended($many))->toBe(200);
});

it('rejects a second deactivation as changing nothing and keeps the actor as the first left it', function (): void {
    [$world, $identity] = deactivationWorld();
    $admin = $identity->addActor(ActorClass::Staff)->id;
    $target = $identity->addActor(ActorClass::Staff)->id;
    $world->deactivate($admin, $target);

    $again = $world->deactivate($admin, $target, 'deactivate-again');

    expect($again->outcome())->toBe(Outcome::Rejected)
        ->and(deactivationErrors($again))->toBe(['validation_failed'])
        ->and(deactivatedRow($target))->toBe('deactivated 2 2 local')
        ->and(StorageTables::superuser()->table('changesets')->where('command', DeactivationWorld::COMMAND)->count())->toBe(1);
});

it('refuses the deactivation function outside the transaction of an actor.deactivate changeset', function (): void {
    [, $identity] = deactivationWorld();
    $target = $identity->addActor(ActorClass::Staff)->id;
    $call = static fn (): mixed => DB::transaction(static function () use ($target): mixed {
        DB::statement("select set_config('cbox_cms.principal', 'actor', true), set_config('cbox_cms.actor', ?, true)", [$target->toString()]);

        return DB::selectOne(ActorDeactivatedWriter::DEACTIVATE, [$target->toString(), 2, 'local', AccessWorld::CHANGESET_ALICE]);
    });

    expect(StorageTables::sqlState($call))->toBe('42501')
        ->and(deactivatedRow($target))->toBe('active 1 1 -');
});
