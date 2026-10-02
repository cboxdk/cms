<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Tests\Console\WorkbenchRegistry;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * cms:sites:sync on this checkout's test database (PRD 11.14, 5.8, 5.9), as the maintenance process
 * runs it at deploy, after cms:install: the configured sites north (da, en) and south (da) are
 * registered through site.register as the installation operator, one changeset with its audit row
 * and its site.registered event per site, each with a root node of kind site, its locales and the
 * route / in each. A second run writes nothing, and path.resolve finds each site by a host of its
 * origin. A site whose configured locales differ from the registered is site_locales_drift, exit
 * 65: nothing of it is rewritten, and a second configured site is still synced.
 */

const SITES_NOW = '2026-03-10T12:00:00Z';

/** @var list<string> the tables a sync writes, counted as the superuser */
const SITES_TABLES = ['nodes', 'sites', 'site_locales', 'node_routes', 'changesets', 'audit', 'events'];

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * The installation as the maintenance process has it: a FakeClock whose day the partitions cover,
 * the workbench's registry, the configured sites given, and the installation operator.
 *
 * @param  array<string, array{origin: string, locales: list<string>}>  $sites
 */
function syncedInstallation(array $sites): void
{
    $clock = new FakeClock(new DateTimeImmutable(SITES_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 611, clock: $clock));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    WorkbenchRegistry::bind();
    config()->set(SitesConfig::CONFIG_KEY, $sites);

    [$installed, $output] = artisanRun('cms:install');

    if ($installed !== 0) {
        throw new RuntimeException('cms:install failed: '.$output);
    }
}

/**
 * Runs an artisan command in-process and gives its exit code and output.
 *
 * @param  array<string, bool|string>  $arguments
 * @return array{int, string}
 */
function artisanRun(string $command, array $arguments = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call($command, $arguments);

    return [$status, $artisan->output()];
}

/**
 * @return array<string, int>
 */
function siteRows(): array
{
    $superuser = StorageTables::superuser();
    $counts = [];

    foreach (SITES_TABLES as $table) {
        $counts[$table] = $superuser->table($table)->count();
    }

    return $counts;
}

/**
 * The registered site with the handle: its id, root node, version and locales, read as the
 * superuser.
 *
 * @return array{id: string, root: string, version: int, locales: list<string>, routes: list<string>, node: list<mixed>}
 */
function registeredSite(string $handle): array
{
    $superuser = StorageTables::superuser();
    $site = (array) ($superuser->table('sites')->where('handle', $handle)->first() ?? throw new RuntimeException("No site {$handle}."));
    $id = is_string($site['id'] ?? null) ? $site['id'] : '';
    $root = is_string($site['root_node_id'] ?? null) ? $site['root_node_id'] : '';
    $node = (array) $superuser->table('nodes')->where('id', $root)->first();
    $locales = $superuser->table('site_locales')->where('site_id', $id)->orderBy('locale')->pluck('locale')->all();
    $routes = $superuser->table('node_routes')->where('site_id', $id)->orderBy('locale')->get(['locale', 'route', 'node_id'])
        ->map(static fn (object $row): string => implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', (array) $row)))
        ->all();

    return [
        'id' => $id,
        'root' => $root,
        'version' => is_int($site['version'] ?? null) ? $site['version'] : 0,
        'locales' => array_values(array_filter($locales, is_string(...))),
        'routes' => array_values($routes),
        'node' => [$node['kind'] ?? null, array_key_exists('parent_id', $node) ? $node['parent_id'] : 'missing', $node['path'] ?? null, $node['version'] ?? null],
    ];
}

/**
 * @return array<array-key, mixed>
 */
function explainedSite(string $url, string $locale): array
{
    [$status, $output] = artisanRun('cms:explain', ['url' => $url, '--locale' => $locale, '--json' => true]);
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $explanation = is_array($document) && is_array($document['explanation'] ?? null) ? $document['explanation'] : [];

    expect($status)->toBe(0, $output);

    return is_array($explanation['site'] ?? null) ? $explanation['site'] : [];
}

it('registers the configured sites with their root nodes and locales, writes nothing the second time, and path.resolve finds each by host', function (): void {
    syncedInstallation([
        'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en']],
        'south' => ['origin' => 'https://south.example', 'locales' => ['da']],
    ]);
    $before = siteRows();

    [$status, $output] = artisanRun('cms:sites:sync');
    $north = registeredSite('north');
    $south = registeredSite('south');
    $after = siteRows();
    $superuser = StorageTables::superuser();
    $operator = $superuser->table('installation')->value('operator_actor_id');
    $changesets = $superuser->table('changesets')->where('command', 'site.register')->orderBy('changeset_id')->get(['changeset_id', 'actor_id', 'surface', 'issuer_kind'])->all();
    $audit = $superuser->table('audit')->where('command', 'site.register')->orderBy('changeset_id')->pluck('aggregates')->all();
    $events = $superuser->table('events')->where('type', 'site.registered')->orderBy('event_id')->pluck('aggregate_id')->all();

    expect($status)->toBe(0, $output)
        ->and($output)->toContain(sprintf('registered north: site %s, root node %s, locales da, en, changeset ', $north['id'], $north['root']))
        ->and($output)->toContain(sprintf('registered south: site %s, root node %s, locales da, changeset ', $south['id'], $south['root']))
        ->and($north['locales'])->toBe(['da', 'en'])
        ->and($south['locales'])->toBe(['da'])
        ->and($north['version'])->toBe(1)
        ->and($north['node'])->toBe(['site', null, str_replace('-', '', $north['root']), 1])
        ->and($south['node'])->toBe(['site', null, str_replace('-', '', $south['root']), 1])
        ->and($north['routes'])->toBe(["da / {$north['root']}", "en / {$north['root']}"])
        ->and($south['routes'])->toBe(["da / {$south['root']}"])
        ->and(array_map(static fn (object $row): array => [$row->actor_id ?? null, $row->surface ?? null, $row->issuer_kind ?? null], $changesets))
        ->toBe([[$operator, 'maintenance', 'system'], [$operator, 'maintenance', 'system']])
        ->and($audit)->toBe([
            sprintf('{site:%s}', $north['id']),
            sprintf('{site:%s}', $south['id']),
        ])
        ->and($events)->toBe([$north['id'], $south['id']])
        ->and($after)->toBe(array_merge($before, [
            'nodes' => $before['nodes'] + 2,
            'sites' => $before['sites'] + 2,
            'site_locales' => $before['site_locales'] + 3,
            'node_routes' => $before['node_routes'] + 3,
            'changesets' => $before['changesets'] + 2,
            'audit' => $before['audit'] + 2,
            'events' => $before['events'] + 2,
        ]));

    [$again, $againOutput] = artisanRun('cms:sites:sync');

    expect($again)->toBe(0, $againOutput)
        ->and($againOutput)->toContain(sprintf('unchanged north: site %s, locales da, en', $north['id']))
        ->and($againOutput)->toContain(sprintf('unchanged south: site %s, locales da', $south['id']))
        ->and(siteRows())->toBe($after)
        ->and(explainedSite('https://north.example/', 'en'))->toMatchArray(['handle' => 'north', 'host' => 'north.example', 'locale' => 'en', 'locale_published' => true, 'site' => $north['id']])
        ->and(explainedSite('https://south.example/', 'da'))->toMatchArray(['handle' => 'south', 'host' => 'south.example', 'locale' => 'da', 'locale_published' => true, 'site' => $south['id']]);
});

it('reports a site whose configured locales drifted as site_locales_drift, exit 65, leaves it unchanged and still syncs the other site', function (): void {
    syncedInstallation(['north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en']]]);
    [$first, $firstOutput] = artisanRun('cms:sites:sync');
    $north = registeredSite('north');
    $before = siteRows();

    config()->set(SitesConfig::CONFIG_KEY, [
        'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'de']],
        'south' => ['origin' => 'https://south.example', 'locales' => ['da']],
    ]);
    [$status, $output] = artisanRun('cms:sites:sync');
    $south = registeredSite('south');

    expect($first)->toBe(0, $firstOutput)
        ->and($status)->toBe(65, $output)
        ->and($output)->toContain(sprintf('drifted north: site %s publishes in da, en, the configuration says da, de; left unchanged', $north['id']))
        ->and($output)->toContain('[site_locales_drift] north: The site north is registered with the locales da, en, and cbox-cms.sites configures da, de. Nothing of the site was changed.')
        ->and($output)->toContain(sprintf('registered south: site %s, root node %s, locales da, changeset ', $south['id'], $south['root']))
        ->and(registeredSite('north'))->toBe($north)
        ->and($south['locales'])->toBe(['da'])
        ->and(siteRows())->toBe(array_merge($before, [
            'nodes' => $before['nodes'] + 1,
            'sites' => $before['sites'] + 1,
            'site_locales' => $before['site_locales'] + 1,
            'node_routes' => $before['node_routes'] + 1,
            'changesets' => $before['changesets'] + 1,
            'audit' => $before['audit'] + 1,
            'events' => $before['events'] + 1,
        ]));
});

it('rejects every site with installation_operator_missing before cms:install, and writes nothing', function (): void {
    $clock = new FakeClock(new DateTimeImmutable(SITES_NOW));
    app()->instance(Clock::class, $clock);
    WorkbenchRegistry::bind();
    config()->set(SitesConfig::CONFIG_KEY, ['north' => ['origin' => 'https://north.example', 'locales' => ['da']]]);

    [$status, $output] = artisanRun('cms:sites:sync');

    expect($status)->toBe(78, $output)
        ->and($output)->toContain('rejected north: nothing was written')
        ->and($output)->toContain('[installation_operator_missing] north: ')
        ->and(StorageTables::superuser()->table('sites')->count())->toBe(0);
});

it('refuses a setting it cannot read with exit 78 and writes nothing', function (): void {
    syncedInstallation([]);
    config()->set(SitesConfig::CONFIG_KEY, ['north' => ['origin' => 'https://north.example']]);

    [$status, $output] = artisanRun('cms:sites:sync');

    expect($status)->toBe(78, $output)
        ->and($output)->toContain('The setting cbox-cms.sites.north.locales must be a list of at least one locale such as ["da", "en"]; it is null. Nothing changed.')
        ->and(StorageTables::superuser()->table('sites')->count())->toBe(0);
});
