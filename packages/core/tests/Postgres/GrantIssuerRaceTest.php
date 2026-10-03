<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Tests\Access\GrantWorld;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Invariant 31 under concurrency (PRD 5.10, 6.2): the escalation guard decides a grant.assign from
 * the issuing actor's own grants, and those grants are part of what the call read, so a change of
 * them that commits after the guard decided and before the call commits makes the call
 * version_conflict instead of giving a role its issuer no longer holds. Each call runs through the
 * real pipeline on a connection of its own (IndependentConnections). ALICE gives CAROL the
 * reviewer role (entry.revise) on FOOTBALL, which the guard allows through her desk grant on
 * FOOTBALL (on NEWS her deny of the desk role on SPORT below it refuses it); right after her call
 * is authorized, BOB, who administers access on ROOT, revokes that desk grant, or takes
 * entry.revise out of the desk role, and commits. ALICE's call then fails with version_conflict on
 * her set of grants and gives CAROL nothing.
 */

const RACE_CAROL = '0192a0c0-0000-7000-8000-0000000000ca';

const RACE_GRANT = '019cd79e-4600-7000-8000-000000000c01';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Seeds AccessWorld with CAROL, an active staff actor without grants, ALICE's grantor role on ROOT,
 * BOB's access administration on ROOT and the reviewer role, and gives the reviewer role's id.
 */
function raceWorld(): RoleId
{
    AccessWorld::seed();
    $world = new GrantWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));
    StorageTables::superuser()->table('actors')->insert(['id' => RACE_CAROL, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => AccessWorld::CREATED_AT]);

    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $world->clock, new FakeIdGenerator(seed: 7171, clock: $world->clock));
    $names = static fn (string ...$names): array => array_map(static fn (string $name): CommandName => new CommandName($name), array_values($names));
    $root = NodeId::fromString(AccessWorld::ROOT);
    $fixtures->grant(ActorId::fromString(AccessWorld::ALICE), $fixtures->role('grantor', ClassificationAccess::Internal, $names('grant.assign')), $root);
    $fixtures->grant(ActorId::fromString(AccessWorld::BOB), $fixtures->role('access_admin', ClassificationAccess::Internal, $names('grant.revoke', 'role.set_permissions')), $root);

    return $fixtures->role('reviewer', ClassificationAccess::Internal, $names('entry.revise'));
}

/**
 * ALICE's grant.assign of the reviewer role to CAROL on FOOTBALL on a connection of its own, with what
 * BOB commits on another right after it was authorized; the result of BOB's call is put in $bob.
 *
 * @param  callable(GrantWorld): WriteResult  $meanwhile
 */
function raceAssign(RoleId $reviewer, callable $meanwhile, ?WriteResult &$bob): WriteResult
{
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $other = new GrantWorld($second->getName(), seed: 72);
    $alice = new GrantWorld($first->getName(), seed: 71, afterAuthorize: static function () use ($meanwhile, $other, &$bob): void {
        $bob = $meanwhile($other);
    });

    return $alice->run(
        AccessWorld::alice(),
        new AssignGrant(GrantId::fromString(RACE_GRANT), ActorId::fromString(RACE_CAROL), $reviewer, NodeId::fromString(AccessWorld::FOOTBALL), GrantEffect::Allow),
        'race-assign',
    );
}

/**
 * @return list<string>
 */
function raceErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

function raceDesk(): string
{
    $desk = StorageTables::superuser()->table('roles')->where('handle', 'desk')->value('id');

    return is_string($desk) ? $desk : throw new AssertionFailedError('AccessWorld has the desk role.');
}

/**
 * The id of the actor's grant of the desk role, on the node given or on any.
 */
function raceDeskGrant(string $actor, ?string $node = null): GrantId
{
    $query = StorageTables::superuser()->table('grants')->where('actor_id', $actor)->where('role_id', raceDesk());
    $grant = ($node === null ? $query : $query->where('node_id', $node))->value('id');

    return is_string($grant) ? GrantId::fromString($grant) : throw new AssertionFailedError('AccessWorld gives the actor the desk role.');
}

it('rejects a grant.assign with version_conflict when the issuer\'s grant the guard relied on is revoked before it commits', function (): void {
    $reviewer = raceWorld();
    $desk = raceDeskGrant(AccessWorld::ALICE, AccessWorld::FOOTBALL);

    $bob = null;
    $result = raceAssign($reviewer, static fn (GrantWorld $world): WriteResult => $world->run(
        AccessWorld::bob(),
        new RevokeGrant($desk, AggregateVersion::first()),
        'race-revoke',
    ), $bob);

    expect($bob?->outcome())->toBe(Outcome::Committed)
        ->and($result->outcome())->toBe(Outcome::Rejected)
        ->and(raceErrors($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('actor_grants:'.AccessWorld::ALICE)
        ->and(StorageTables::superuser()->table('grants')->where('actor_id', RACE_CAROL)->count())->toBe(0)
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'grant.assign')->count())->toBe(0);
});

it('rejects a grant.assign with version_conflict when the role of the issuer\'s grant loses the permission before it commits', function (): void {
    $reviewer = raceWorld();

    $bob = null;
    $result = raceAssign($reviewer, static fn (GrantWorld $world): WriteResult => $world->run(
        AccessWorld::bob(),
        new SetRolePermissions(RoleId::fromString(raceDesk()), AggregateVersion::first(), [new CommandName('entry.create')]),
        'race-set-permissions',
    ), $bob);

    expect($bob?->outcome())->toBe(Outcome::Committed)
        ->and($result->outcome())->toBe(Outcome::Rejected)
        ->and(raceErrors($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('actor_grants:'.AccessWorld::ALICE)
        ->and(StorageTables::superuser()->table('grants')->where('actor_id', RACE_CAROL)->count())->toBe(0);
});

it('commits a grant.assign when only another actor\'s grants change meanwhile', function (): void {
    $reviewer = raceWorld();
    $sport = raceDeskGrant(AccessWorld::BOB);

    $bob = null;
    $result = raceAssign($reviewer, static fn (GrantWorld $world): WriteResult => $world->run(
        AccessWorld::bob(),
        new RevokeGrant($sport, AggregateVersion::first()),
        'race-unrelated',
    ), $bob);

    expect($bob?->outcome())->toBe(Outcome::Committed)
        ->and($result->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('grants')->where('actor_id', RACE_CAROL)->count())->toBe(1);
});

it('commits a grant the issuer gives itself, whose set of grants the action and the guard read at one version', function (): void {
    $reviewer = raceWorld();

    $result = new GrantWorld()->run(
        AccessWorld::alice(),
        new AssignGrant(GrantId::fromString(RACE_GRANT), ActorId::fromString(AccessWorld::ALICE), $reviewer, NodeId::fromString(AccessWorld::FOOTBALL), GrantEffect::Allow),
        'race-self',
    );

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('grants')->where('id', RACE_GRANT)->value('actor_id'))->toBe(AccessWorld::ALICE);
});
