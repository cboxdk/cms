<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Adapter\GrantRoleContentChangedWriter;
use Cbox\Cms\Core\Access\Adapter\RoleCreatedWriter;
use Cbox\Cms\Core\Access\Adapter\RolePermissionsSetWriter;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\GrantWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ScopedAggregates;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * role.create and role.set_permissions on Postgres (PRD 5.10, 6.4, invariant 31): the real
 * pipeline, authorizer, escalation guard, commit and writers as the app role, over AccessWorld.
 * ALICE holds the role commands on ROOT besides her desk role (entry.create and entry.revise) on
 * NEWS less SPORT but FOOTBALL. The role reviewer (entry.revise) is granted to CAROL on NEWS and to
 * BOB on FOOTBALL. A change of its permissions commits one changeset that moves the role and both
 * grants, with grant.changed for each holder, and the kernel's command authorizer then allows CAROL
 * the added command. The guard refuses what ALICE does not hold where the role is granted, and a
 * change that makes the granted role administrative. The app role writes no role itself, and the
 * owner functions that do refuse to run outside their command's changeset.
 */

const ROLE_CAROL = '0192a0c0-0000-7000-8000-0000000000da';

const ROLE_NEW = '019cd79e-4600-7000-8000-000000000c01';

const ROLE_OTHER = '019cd79e-4600-7000-8000-000000000c02';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{GrantWorld, RoleId, list<GrantId>}
 */
function roleCommandsWorld(): array
{
    AccessWorld::seed();
    $world = new GrantWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));
    StorageTables::superuser()->table('actors')->insert(['id' => ROLE_CAROL, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => AccessWorld::CREATED_AT]);

    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $world->clock, new FakeIdGenerator(seed: 6171, clock: $world->clock));
    $names = static fn (string ...$names): array => array_map(static fn (string $name): CommandName => new CommandName($name), array_values($names));
    $fixtures->grant(ActorId::fromString(AccessWorld::ALICE), $fixtures->role('role_admin', ClassificationAccess::Internal, $names('role.create', 'role.set_permissions', 'grant.assign')), NodeId::fromString(AccessWorld::ROOT));
    $reviewer = $fixtures->role('reviewer', ClassificationAccess::Internal, $names('entry.revise'));

    return [$world, $reviewer, [
        $fixtures->grant(ActorId::fromString(ROLE_CAROL), $reviewer, NodeId::fromString(AccessWorld::NEWS)),
        $fixtures->grant(ActorId::fromString(AccessWorld::BOB), $reviewer, NodeId::fromString(AccessWorld::FOOTBALL)),
    ]];
}

function roleChangeset(WriteResult $result): string
{
    return $result->receipt->changesetId instanceof ChangesetId
        ? $result->receipt->changesetId->toString()
        : throw new AssertionFailedError('The call committed no changeset: '.implode(', ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
}

/**
 * @return list<string>
 */
function roleErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * @param  list<string>  $permissions
 */
function reviewerPermissions(RoleId $role, array $permissions, int $version = 1): SetRolePermissions
{
    return new SetRolePermissions($role, new AggregateVersion($version), array_map(static fn (string $name): CommandName => new CommandName($name), $permissions));
}

/**
 * Whether the kernel's command authorizer lets CAROL run the command on NEWS.
 */
function carolMay(string $command): Authorization
{
    $carol = new ActorPrincipal(ActorId::fromString(ROLE_CAROL), [], IssuerKind::Service, ClassificationAccess::Internal);

    return AuthorizerWorld::within($carol, static fn (AccessContext $access): Authorization => app(CommandAuthorizer::class)->authorize(
        $access,
        new CommandName($command),
        new RenameProbe(EntryId::fromString(AccessWorld::ENTRY_NEWS), TypeId::fromString(AccessWorld::TYPE), NodeId::fromString(AccessWorld::NEWS), new FieldValues),
        new ScopedAggregates(AuthorizationScope::on(new AuthorizationTarget(NodeId::fromString(AccessWorld::NEWS)))),
        Envelope::external(IssuingSurface::Rest, EnvelopeIssuer::Human, $carol->actor, new IdempotencyKey('carol-may'), new CorrelationId('carol-may')),
    ));
}

it('sets a role\'s permissions in one changeset with grant.changed for each holder, and the kernel\'s authorizer allows a holder the added command', function (): void {
    [$world, $reviewer, $grants] = roleCommandsWorld();
    $before = carolMay('entry.create');

    $result = $world->run(AccessWorld::alice(), reviewerPermissions($reviewer, ['entry.revise', 'entry.create']), 'set-permissions-1');
    $changeset = roleChangeset($result);
    $superuser = StorageTables::superuser();
    $payload = static fn (string $actor, string $node): string => sprintf('{"node": {"identifier": "%s"}, "role": {"identifier": "%s"}, "actor": {"identifier": "%s"}}', $node, $reviewer->toString(), $actor);
    $expectedEvents = [
        $grants[0]->toString().' 2 grant.changed 1 '.$payload(ROLE_CAROL, AccessWorld::NEWS),
        $grants[1]->toString().' 2 grant.changed 1 '.$payload(AccessWorld::BOB, AccessWorld::FOOTBALL),
    ];
    sort($expectedEvents);
    $events = StorageTables::texts($superuser, 'select concat_ws(\' \', aggregate_id, aggregate_version, type, type_version, data::text) as value from events where changeset_id = ?', [$changeset]);
    sort($events);

    expect($before->allowed())->toBeFalse()
        ->and($result->outcome())->toBe(Outcome::Committed)
        ->and(carolMay('entry.create')->allowed())->toBeTrue()
        ->and(carolMay('entry.publish')->allowed())->toBeFalse()
        ->and($events)->toBe($expectedEvents)
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', command, actor_id) as value from changesets where changeset_id = ?', [$changeset]))
        ->toBe(['role.set_permissions '.AccessWorld::ALICE])
        ->and(StorageTables::texts($superuser, 'select array_to_string(aggregates, \',\') as value from audit where changeset_id = ?', [$changeset]))
        ->toHaveCount(1)
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', handle, version) as value from roles where id = ?', [$reviewer->toString()]))->toBe(['reviewer 2'])
        ->and(StorageTables::texts($superuser, 'select command as value from role_permissions where role_id = ? order by command', [$reviewer->toString()]))->toBe(['entry.create', 'entry.revise'])
        ->and(StorageTables::texts($superuser, 'select version::text as value from grants where role_id = ? order by id', [$reviewer->toString()]))->toBe(['2', '2']);

    $removed = $world->run(AccessWorld::alice(), reviewerPermissions($reviewer, ['entry.create'], 2), 'set-permissions-2');

    expect($removed->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::texts($superuser, 'select command as value from role_permissions where role_id = ? order by command', [$reviewer->toString()]))->toBe(['entry.create'])
        ->and(StorageTables::texts($superuser, 'select version::text as value from grants where role_id = ? order by id', [$reviewer->toString()]))->toBe(['3', '3']);
});

it('creates a role with its permissions, and refuses its handle again, a name the registry does not know and a ceiling above the issuer\'s access', function (): void {
    [$world] = roleCommandsWorld();
    $alice = AccessWorld::alice();
    $permissions = [new CommandName('entry.create'), new CommandName('path.resolve')];

    $created = $world->run($alice, new CreateRole(RoleId::fromString(ROLE_NEW), new RoleHandle('night_desk'), ClassificationAccess::Internal, $permissions), 'create-1');
    $again = $world->run($alice, new CreateRole(RoleId::fromString(ROLE_OTHER), new RoleHandle('night_desk'), ClassificationAccess::Internal, $permissions), 'create-2');
    $unknown = $world->run($alice, new CreateRole(RoleId::fromString(ROLE_OTHER), new RoleHandle('day_desk'), ClassificationAccess::Internal, [new CommandName('entry.nothing')]), 'create-3');
    $above = $world->run($alice, new CreateRole(RoleId::fromString(ROLE_OTHER), new RoleHandle('day_desk'), ClassificationAccess::Sensitive, $permissions), 'create-4');
    $superuser = StorageTables::superuser();

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', handle, classification_ceiling, version) as value from roles where id = ?', [ROLE_NEW]))->toBe(['night_desk internal 1'])
        ->and(StorageTables::texts($superuser, 'select command as value from role_permissions where role_id = ? order by command', [ROLE_NEW]))->toBe(['entry.create', 'path.resolve'])
        ->and(StorageTables::texts($superuser, 'select array_to_string(aggregates, \',\') as value from audit where changeset_id = ?', [roleChangeset($created)]))->toBe(['role:'.ROLE_NEW])
        ->and(StorageTables::texts($superuser, 'select type as value from events where changeset_id = ?', [roleChangeset($created)]))->toBe([])
        ->and(roleErrors($again))->toBe(['validation_failed'])
        ->and(roleErrors($unknown))->toBe(['validation_failed'])
        ->and(roleErrors($above))->toBe(['grant_escalation_refused'])
        ->and($superuser->table('roles')->where('id', ROLE_OTHER)->count())->toBe(0);
});

it('refuses to add what the issuer lacks where the role is granted, and a change that makes it administrative, and writes nothing', function (): void {
    [$world, $reviewer] = roleCommandsWorld();
    $alice = AccessWorld::alice();

    $escalation = $world->run($alice, reviewerPermissions($reviewer, ['entry.revise', 'entry.publish']), 'set-escalation');
    $administrative = $world->run($alice, reviewerPermissions($reviewer, ['entry.revise', 'grant.assign']), 'set-administrative');
    $stale = $world->run($alice, reviewerPermissions($reviewer, ['entry.create'], 2), 'set-stale');
    $superuser = StorageTables::superuser();

    expect(roleErrors($escalation))->toBe(['grant_escalation_refused'])
        ->and(roleErrors($administrative))->toBe(['step_up_required'])
        ->and(roleErrors($stale))->toBe(['version_conflict'])
        ->and(StorageTables::texts($superuser, 'select concat_ws(\' \', handle, version) as value from roles where id = ?', [$reviewer->toString()]))->toBe(['reviewer 1'])
        ->and(StorageTables::texts($superuser, 'select command as value from role_permissions where role_id = ?', [$reviewer->toString()]))->toBe(['entry.revise']);
});

it('refuses the app role a direct write of roles and the role functions outside their command\'s changeset', function (): void {
    [, $reviewer, $grants] = roleCommandsWorld();
    $context = new ActorContext(app(ConnectionResolverInterface::class));
    $alice = app(AccessContexts::class)->for(AccessWorld::alice());
    $as = static fn (callable $work): mixed => DB::transaction(static function () use ($context, $alice, $work): mixed {
        $context->set($alice);

        return $work();
    });

    expect(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::insert(
        "insert into roles (id, handle, classification_ceiling, version, created_at) values (?, 'sneaky', 'sensitive', 1, now())",
        [ROLE_NEW],
    ))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::statement(RoleCreatedWriter::CREATE, [
            ROLE_NEW, 'sneaky', 'sensitive', '{grant.assign}', 1, '2026-03-10 12:00:00+00', AccessWorld::CHANGESET_ALICE,
        ]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::statement(RolePermissionsSetWriter::SET, [
            $reviewer->toString(), '{grant.assign}', 2, '2026-03-10 12:00:00+00', AccessWorld::CHANGESET_ALICE,
        ]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::select(GrantRoleContentChangedWriter::CHANGE, [
            '{'.$grants[0]->toString().'}', '{2}', AccessWorld::CHANGESET_ALICE,
        ]))))->toBe('42501')
        ->and(StorageTables::superuser()->table('roles')->where('id', ROLE_NEW)->count())->toBe(0)
        ->and(StorageTables::texts(StorageTables::superuser(), 'select command as value from role_permissions where role_id = ?', [$reviewer->toString()]))->toBe(['entry.revise']);
});
