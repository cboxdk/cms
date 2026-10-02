<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Adapter\GrantAssignedWriter;
use Cbox\Cms\Core\Access\Adapter\GrantRevokedWriter;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Tests\Access\GrantWorld;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * grant.assign and grant.revoke on Postgres (PRD 5.10, 6.4, invariant 31): the real pipeline,
 * authorizer, escalation guard, commit and writers as the app role, over AccessWorld. ALICE holds
 * grant.assign and grant.revoke on ROOT besides her desk role (entry.create and entry.revise) on
 * NEWS less SPORT but FOOTBALL; CAROL is an active staff actor with no grant. An assign commits one
 * changeset with its audit row and grant.changed, and the access resolver gives CAROL the new
 * region; a revoke commits one more, and CAROL reaches nothing again. The app role writes no grant
 * itself, and the owner functions that do refuse to run outside their command's changeset.
 */

const GRANT_CAROL = '0192a0c0-0000-7000-8000-0000000000ca';

const GRANT_PENDING = '0192a0c0-0000-7000-8000-0000000000cb';

const GRANT_ID = '019cd79e-4600-7000-8000-000000000b01';

const GRANT_OTHER = '019cd79e-4600-7000-8000-000000000b02';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{GrantWorld, array{reviewer: RoleId, publisher: RoleId, administrator: RoleId}}
 */
function grantWorld(): array
{
    AccessWorld::seed();
    $world = new GrantWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));
    $superuser = StorageTables::superuser();

    foreach ([GRANT_CAROL => 'active', GRANT_PENDING => 'pending'] as $actor => $state) {
        $superuser->table('actors')->insert(['id' => $actor, 'actor_class' => 'staff', 'state' => $state, 'version' => 1, 'credential_generation' => 1, 'created_at' => AccessWorld::CREATED_AT]);
    }

    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $world->clock, new FakeIdGenerator(seed: 6161, clock: $world->clock));
    $names = static fn (string ...$names): array => array_map(static fn (string $name): CommandName => new CommandName($name), array_values($names));
    $fixtures->grant(ActorId::fromString(AccessWorld::ALICE), $fixtures->role('grantor', ClassificationAccess::Internal, $names('grant.assign', 'grant.revoke')), NodeId::fromString(AccessWorld::ROOT));

    return [$world, [
        'reviewer' => $fixtures->role('reviewer', ClassificationAccess::Internal, $names('entry.revise')),
        'publisher' => $fixtures->role('publisher', ClassificationAccess::Internal, $names('entry.revise', 'entry.publish')),
        'administrator' => $fixtures->role('administrator', ClassificationAccess::Internal, $names('entry.revise', 'grant.assign')),
    ]];
}

function assignTo(string $actor, RoleId $role, string $grant = GRANT_ID): AssignGrant
{
    return new AssignGrant(GrantId::fromString($grant), ActorId::fromString($actor), $role, NodeId::fromString(AccessWorld::NEWS), GrantEffect::Allow);
}

function grantChangeset(WriteResult $result): string
{
    return $result->receipt->changesetId instanceof ChangesetId
        ? $result->receipt->changesetId->toString()
        : throw new AssertionFailedError('The call committed no changeset: '.implode(', ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
}

/**
 * @return list<string>
 */
function grantErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The paths of the regions the kernel compiles for CAROL from her grants.
 *
 * @return list<string>
 */
function carolRegions(): array
{
    $connection = DB::connection();
    $connection->beginTransaction();

    try {
        $context = app(AccessResolver::class)->resolve(new ActorPrincipal(ActorId::fromString(GRANT_CAROL), [], IssuerKind::Service, ClassificationAccess::Sensitive));

        return array_map(static fn (AccessRegion $region): string => $region->path->value, $context->regions);
    } finally {
        $connection->rollBack();
    }
}

it('assigns and revokes a grant, one changeset each with its audit row and grant.changed, and the resolver follows', function (): void {
    [$world, $roles] = grantWorld();
    $before = carolRegions();

    $assigned = $world->run(AccessWorld::alice(), assignTo(GRANT_CAROL, $roles['reviewer']), 'assign-1');
    $afterAssign = carolRegions();
    $revoked = $world->run(AccessWorld::alice(), new RevokeGrant(GrantId::fromString(GRANT_ID), AggregateVersion::first()), 'revoke-1');
    $changesets = [grantChangeset($assigned), grantChangeset($revoked)];
    $superuser = StorageTables::superuser();
    $in = ['{'.implode(',', $changesets).'}'];
    $grant = sprintf('{"node": {"identifier": "%s"}, "role": {"identifier": "%s"}, "actor": {"identifier": "%s"}}', AccessWorld::NEWS, $roles['reviewer']->toString(), GRANT_CAROL);

    expect($assigned->outcome())->toBe(Outcome::Committed)
        ->and($revoked->outcome())->toBe(Outcome::Committed)
        ->and($before)->toBe([])
        ->and($afterAssign)->toBe([AccessWorld::path(AccessWorld::ROOT, AccessWorld::NEWS)])
        ->and(carolRegions())->toBe([])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', command, actor_id) as value from changesets where changeset_id = any(?::uuid[]) order by changeset_id', $in))
        ->toBe(['grant.assign '.AccessWorld::ALICE, 'grant.revoke '.AccessWorld::ALICE])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', command, array_to_string(aggregates, \',\')) as value from audit where changeset_id = any(?::uuid[]) order by changeset_id', $in))
        ->toBe(['grant.assign grant:'.GRANT_ID, 'grant.revoke grant:'.GRANT_ID])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', aggregate_id, aggregate_version, type, type_version, data::text) as value from events where changeset_id = any(?::uuid[]) order by event_id', $in))
        ->toBe([GRANT_ID.' 1 grant.changed 1 '.$grant, GRANT_ID.' 2 grant.changed 1 '.$grant])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', actor_id, role_id, node_id, effect, version, ended_changeset_id) as value from grants where id = ?', [GRANT_ID]))
        ->toBe([implode(' ', [GRANT_CAROL, $roles['reviewer']->toString(), AccessWorld::NEWS, 'allow', 2, $changesets[1]])]);
});

it('refuses an escalation, an administrative role, a pending actor and a role held on the node already, and writes nothing', function (): void {
    [$world, $roles] = grantWorld();
    $alice = AccessWorld::alice();

    $escalation = $world->run($alice, assignTo(GRANT_CAROL, $roles['publisher']), 'assign-publisher');
    $administrative = $world->run($alice, assignTo(GRANT_CAROL, $roles['administrator']), 'assign-administrator');
    $pending = $world->run($alice, assignTo(GRANT_PENDING, $roles['reviewer']), 'assign-pending');
    $world->run($alice, assignTo(GRANT_CAROL, $roles['reviewer']), 'assign-reviewer');
    $again = $world->run($alice, assignTo(GRANT_CAROL, $roles['reviewer'], GRANT_OTHER), 'assign-again');
    $stale = $world->run($alice, new RevokeGrant(GrantId::fromString(GRANT_ID), new AggregateVersion(2)), 'revoke-stale');

    expect(grantErrors($escalation))->toBe(['grant_escalation_refused'])
        ->and(grantErrors($administrative))->toBe(['step_up_required'])
        ->and(grantErrors($pending))->toBe(['validation_failed'])
        ->and(grantErrors($again))->toBe(['validation_failed'])
        ->and(grantErrors($stale))->toBe(['version_conflict'])
        ->and(StorageTables::texts(StorageTables::superuser(), 'select id::text as value from grants where actor_id in (?, ?) order by id', [GRANT_CAROL, GRANT_PENDING]))->toBe([GRANT_ID]);
});

it('refuses the app role a direct write of grants and the grant functions outside their command\'s changeset', function (): void {
    [, $roles] = grantWorld();
    $context = new ActorContext(app(ConnectionResolverInterface::class));
    $alice = app(AccessContexts::class)->for(AccessWorld::alice());
    $as = static fn (callable $work): mixed => DB::transaction(static function () use ($context, $alice, $work): mixed {
        $context->set($alice);

        return $work();
    });

    expect(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::insert(
        "insert into grants (id, actor_id, role_id, node_id, effect, version, created_at) values (?, ?, ?, ?, 'allow', 1, now())",
        [GRANT_ID, GRANT_CAROL, $roles['reviewer']->toString(), AccessWorld::NEWS],
    ))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::statement(GrantAssignedWriter::ASSIGN, [
            GRANT_ID, GRANT_CAROL, $roles['reviewer']->toString(), AccessWorld::NEWS, 'allow', null, 1, '2026-03-10 12:00:00+00', AccessWorld::CHANGESET_ALICE,
        ]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::selectOne(GrantRevokedWriter::REVOKE, [GRANT_ID, 2, AccessWorld::CHANGESET_ALICE]))))->toBe('42501')
        ->and(StorageTables::superuser()->table('grants')->where('actor_id', GRANT_CAROL)->count())->toBe(0);
});
