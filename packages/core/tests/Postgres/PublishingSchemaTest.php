<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * What the migration of entry.publish and entry.unpublish gives the schema (PRD 5.6, 5.10, 6.4):
 * the release log records an unrelease without a revision; the owner function that lists an
 * entry's placements in every locale runs only under an actor context and reads past the actor's
 * regions; and the one that closes a placement runs only in the transaction of an entry.unpublish
 * changeset by the context's actor, so no other command can hide a placement it cannot reach.
 */

beforeEach(function (): void {
    StorageTables::seedPlacement();
});

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Runs the call as the app role in a transaction under the context, and rolls back.
 *
 * @param  callable(): mixed  $call
 */
function publishingSchemaAs(?AccessContext $context, callable $call): mixed
{
    $db = DB::connection();
    $db->beginTransaction();

    try {
        if ($context instanceof AccessContext) {
            new ActorContext(app(ConnectionResolverInterface::class))->set($context);
        }

        return $call();
    } finally {
        $db->rollBack();
    }
}

/**
 * An active actor's context that reaches no node.
 */
function publishingSchemaActor(): AccessContext
{
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

    return new AccessContext(new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Internal);
}

/**
 * @param  array<string, string|null>  $changes
 * @return array<string, string|null>
 */
function publishingLogRow(array $changes): array
{
    return array_merge([
        'entry_id' => StorageTables::ENTRY,
        'variant' => 'shared',
        'action' => 'unreleased',
        'revision_id' => null,
        'effective_at' => StorageTables::CREATED_AT,
        'changeset_id' => StorageTables::CHANGESET,
    ], $changes);
}

it('records an unrelease in the release log without a revision, and no other action', function (): void {
    $superuser = StorageTables::superuser();
    $superuser->table('release_log')->insert(publishingLogRow([]));

    expect($superuser->table('release_log')->pluck('action')->all())->toBe(['unreleased'])
        ->and(StorageTables::violation(static fn () => $superuser->table('release_log')->insert(publishingLogRow(['action' => 'hidden']))))->toBe('23514 release_log_action')
        ->and(StorageTables::violation(static fn () => $superuser->table('release_log')->insert(publishingLogRow(['revision_id' => '2']))))->toBe('23514 release_log_revision');
});

it('lists an entry\'s placements in every locale only under an actor context, past the actor\'s regions', function (): void {
    $actor = publishingSchemaActor();
    $sql = 'select locale, placement_id::text as placement, canonical from cms_entry_placement_locales(?::uuid)';

    expect(publishingSchemaAs(null, static fn (): string => StorageTables::sqlState(static fn () => DB::connection()->select($sql, [StorageTables::ENTRY]))))->toBe('42501')
        ->and(publishingSchemaAs($actor, static fn (): array => array_map(
            static fn (mixed $row): string => is_object($row) && property_exists($row, 'locale') && property_exists($row, 'placement') && is_string($row->locale) && is_string($row->placement) ? $row->locale.' '.$row->placement : '',
            DB::connection()->select($sql, [StorageTables::ENTRY]),
        )))->toBe(['da '.StorageTables::PLACEMENT]);
});

it('closes a placement only in the transaction of an entry.unpublish changeset by the context\'s actor', function (): void {
    $actor = publishingSchemaActor();
    $close = static fn (): string => StorageTables::sqlState(static fn () => DB::connection()->select('select * from cms_placement_close(?::uuid, ?, 2, ?::uuid)', [StorageTables::PLACEMENT, 'da', StorageTables::CHANGESET]));

    expect(publishingSchemaAs(null, $close))->toBe('42501')
        ->and(publishingSchemaAs($actor, $close))->toBe('42501')
        ->and(StorageTables::superuser()->table('placement_locales')->value('visibility'))->toBe('live')
        ->and(StorageTables::superuser()->table('placements')->value('version'))->toBe(1);
});
