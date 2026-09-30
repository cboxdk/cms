<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * What the migration of the placement commands gives the app role (PRD 5.7, 5.9, 5.10): every
 * context reads the sites and their locales, and the app role writes none of them; the owner
 * functions that read and change placements past the actor's regions run only under an actor
 * context, and the one that sets the canonical flag only in the transaction of a changeset by the
 * context's actor.
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
function placementSchemaAs(?AccessContext $context, callable $call): mixed
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

function placementSchemaActor(): AccessContext
{
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

    return new AccessContext(
        new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
        [new AccessRegion(new NodePath(StorageTables::label(StorageTables::ROOT)))],
        ClassificationAccess::Internal,
    );
}

it('lets every context read the sites and their locales, and no context write them', function (): void {
    $actor = placementSchemaActor();
    $read = static fn (): array => DB::connection()->table('site_locales')->pluck('locale')->all();

    expect(placementSchemaAs(null, $read))->toBe([])
        ->and(placementSchemaAs(AccessContext::anonymous(), $read))->toBe(['da'])
        ->and(placementSchemaAs($actor, $read))->toBe(['da'])
        ->and(placementSchemaAs($actor, static fn (): int => DB::connection()->table('sites')->where('id', StorageTables::SITE)->update(['version' => 2])))->toBe(0)
        ->and(StorageTables::superuser()->table('sites')->value('version'))->toBe(1)
        ->and(placementSchemaAs($actor, static fn (): string => StorageTables::sqlState(static fn () => DB::connection()->table('site_locales')->insert(['site_id' => StorageTables::SITE, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT]))))->toBe('42501');
});

it('runs the owner functions only under an actor context, and sets the canonical flag only for a changeset of this transaction', function (): void {
    $actor = placementSchemaActor();
    $state = static fn (string $sql, array $bindings): string => StorageTables::sqlState(static fn () => DB::connection()->select($sql, $bindings));

    expect(placementSchemaAs(null, static fn (): string => $state('select * from cms_placement_locales(?::uuid, ?)', [StorageTables::ENTRY, 'da'])))->toBe('42501')
        ->and(placementSchemaAs(AccessContext::anonymous(), static fn (): string => $state('select cms_placement_lock(?::uuid, false)', [StorageTables::PLACEMENT])))->toBe('42501')
        ->and(placementSchemaAs($actor, static fn (): array => array_map(static fn (mixed $row): mixed => is_object($row) && property_exists($row, 'canonical') ? $row->canonical : null, DB::connection()->select('select * from cms_placement_locales(?::uuid, ?)', [StorageTables::ENTRY, 'da']))))->toBe([true])
        ->and(placementSchemaAs($actor, static fn (): string => $state('select cms_placement_set_canonical(?::uuid, ?, false, 2, ?::uuid)', [StorageTables::PLACEMENT, 'da', StorageTables::CHANGESET])))->toBe('42501')
        ->and(StorageTables::superuser()->table('placement_locales')->value('canonical'))->toBeTrue();
});
