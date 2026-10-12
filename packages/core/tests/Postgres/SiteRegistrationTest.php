<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Tests\Identity\RegistrationWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * The structure is written only in a site command's changeset (PRD 5.8, 5.9, 11.14): under the
 * installation operator's actor context, outside the transaction of a site.register changeset by
 * that actor, the app role may not insert a root node, a site, a site locale or a root route, and
 * may not move a site's version or retire a locale, each SQLSTATE 42501 or no row, and nothing is
 * written; the blanket owner policies sites_owner_write and site_locales_owner_write are gone, so
 * the owner writes neither table either. cms:sites:sync shows the writes inside their changesets
 * (packages/cli/tests/Postgres/SitesSyncCommandTest.php), and
 * packages/core/tests/Postgres/SiteLocaleCommandsTest.php the locale commands.
 */

const REFUSED_SITE = '019cd79e-4600-7000-8000-000000000c01';

const REFUSED_ROOT = '019cd79e-4600-7000-8000-000000000c02';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

it('refuses the app role a write of the structure outside a site command\'s changeset, and holds no owner write policy on the site tables', function (): void {
    $world = new RegistrationWorld;
    app(PartitionFixtures::class)->coverClock($world->clock, new DateInterval('P1D'));
    $operator = $world->install();
    $context = app(AccessContexts::class)->for(new ActorPrincipal($operator, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()));
    $actorContext = new ActorContext(app(ConnectionResolverInterface::class));
    $as = static fn (callable $work): mixed => DB::transaction(static function () use ($actorContext, $context, $work): mixed {
        $actorContext->set($context);

        return $work();
    });
    $path = str_replace('-', '', REFUSED_ROOT);
    $superuser = StorageTables::superuser();
    $superuser->table('nodes')->insert(StorageTables::node(StorageTables::ROOT, kind: 'site'));
    $superuser->table('sites')->insert(['id' => StorageTables::SITE, 'handle' => 'north', 'root_node_id' => StorageTables::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT]);
    $superuser->table('site_locales')->insert(['site_id' => StorageTables::SITE, 'locale' => 'da', 'created_at' => StorageTables::CREATED_AT]);

    expect(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::insert(
        "insert into nodes (id, parent_id, kind, path, mount_source_id, version, created_at) values (?, null, 'site', ?::ltree, null, 1, now())",
        [REFUSED_ROOT, $path],
    ))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::table('sites')->insert(['id' => REFUSED_SITE, 'handle' => 'south', 'root_node_id' => StorageTables::ROOT, 'version' => 1, 'created_at' => StorageTables::CREATED_AT]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::table('site_locales')->insert(['site_id' => StorageTables::SITE, 'locale' => 'en', 'created_at' => StorageTables::CREATED_AT]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::table('node_routes')->insert(['site_id' => StorageTables::SITE, 'locale' => 'da', 'route' => '/', 'node_id' => StorageTables::ROOT, 'created_at' => StorageTables::CREATED_AT]))))->toBe('42501')
        ->and($as(static fn (): int => DB::table('sites')->where('id', StorageTables::SITE)->update(['version' => 2])))->toBe(0)
        ->and($as(static fn (): int => DB::table('site_locales')->where('site_id', StorageTables::SITE)->update(['retired_at' => StorageTables::CREATED_AT])))->toBe(0)
        ->and(StorageTables::texts(DB::connection('pgsql_owner'), "select policyname::text as value from pg_policies where tablename in ('sites', 'site_locales') and policyname like '%owner_write' order by 1"))->toBe([])
        ->and($superuser->table('sites')->count())->toBe(1)
        ->and($superuser->table('sites')->value('version'))->toBe(1)
        ->and($superuser->table('site_locales')->whereNull('retired_at')->count())->toBe(1)
        ->and($superuser->table('nodes')->count())->toBe(1)
        ->and($superuser->table('node_routes')->count())->toBe(0);
});
