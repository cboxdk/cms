<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Structure\Adapter\SiteRegisteredWriter;
use Cbox\Cms\Core\Tests\Identity\RegistrationWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * The structure stays the owner's to write (PRD 5.8, 5.9, 11.14): under the installation operator's
 * actor context the app role may not insert a site, a node, a site locale or a route itself, and
 * the owner function cms_structure_register_site, which site.register's writer calls, refuses to
 * run outside the transaction of a site.register changeset by the context's actor. Both are
 * SQLSTATE 42501, and nothing is written. cms:sites:sync shows the function inside its changeset
 * (packages/cli/tests/Postgres/SitesSyncCommandTest.php).
 */

const REFUSED_SITE = '019cd79e-4600-7000-8000-000000000c01';

const REFUSED_ROOT = '019cd79e-4600-7000-8000-000000000c02';

const REFUSED_CHANGESET = '019cd79e-4600-7000-8000-000000000c03';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

it('refuses the app role a direct write of the structure and the owner function outside a site.register changeset', function (): void {
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

    expect(StorageTables::sqlState(static fn (): mixed => $as(static fn (): bool => DB::insert(
        "insert into nodes (id, parent_id, kind, path, mount_source_id, version, created_at) values (?, null, 'site', ?::ltree, null, 1, now())",
        [REFUSED_ROOT, $path],
    ))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => $as(static fn (): mixed => DB::statement(SiteRegisteredWriter::REGISTER, [
            REFUSED_SITE, 'north', REFUSED_ROOT, '{da,en}', 1, '2026-03-10 12:00:00+00', REFUSED_CHANGESET,
        ]))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): mixed => DB::statement(SiteRegisteredWriter::REGISTER, [
            REFUSED_SITE, 'north', REFUSED_ROOT, '{da,en}', 1, '2026-03-10 12:00:00+00', REFUSED_CHANGESET,
        ])))->toBe('42501')
        ->and(StorageTables::superuser()->table('sites')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('nodes')->count())->toBe(0);
});
