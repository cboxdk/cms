<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * cms:install on this checkout's test database (PRD 5.16, 3.3, invariant 37), as the maintenance
 * process runs it: the genesis on the owner connection, at a FakeClock whose day the partitions
 * cover. The first run creates the installation operator, an active service actor, in one genesis
 * changeset with its audit row and its events, and the row of `installation`; a second run writes
 * nothing, prints the same id and exits 0. A process that serves HTTP, a process without the owner
 * connection and a time no partition covers are refused with the catalog's exit codes, and write
 * nothing.
 */

const INSTALL_NOW = '2026-03-10T12:00:00Z';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * Runs cms:install in-process at INSTALL_NOW and gives its exit code and output.
 *
 * @return array{int, string}
 */
function install(bool $covered = true, bool $ownerConnection = true): array
{
    $clock = new FakeClock(new DateTimeImmutable(INSTALL_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(clock: $clock));

    if ($covered) {
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    }

    if (! $ownerConnection) {
        config(['cbox-cms.database.owner_connection' => null]);
    }

    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:install');

    return [$status, $artisan->output()];
}

/**
 * The rows the genesis writes, counted as the superuser.
 *
 * @return array<string, int>
 */
function installationRows(): array
{
    $superuser = StorageTables::superuser();
    $counts = [];

    foreach (['actors', 'installation', 'changeset_register', 'changesets', 'audit', 'events'] as $table) {
        $count = $superuser->table($table)->count();
        $counts[$table] = $count;
    }

    return $counts;
}

it('creates the operator once in a genesis changeset, and a second run writes nothing and exits 0', function (): void {
    [$status, $output] = install();
    $superuser = StorageTables::superuser();
    $operator = $superuser->table('installation')->value('operator_actor_id');
    $actor = (array) $superuser->table('actors')->first();
    $changeset = (array) $superuser->table('changesets')->first();
    $audit = (array) $superuser->table('audit')->first();
    $events = $superuser->table('events')->orderBy('event_id')->pluck('type')->all();
    $after = installationRows();

    expect($status)->toBe(0, $output)
        ->and($after)->toBe(['actors' => 1, 'installation' => 1, 'changeset_register' => 1, 'changesets' => 1, 'audit' => 1, 'events' => 2])
        ->and($operator)->toBeString()
        ->and($output)->toContain('Created the installation operator '.(is_string($operator) ? $operator : ''))
        ->and([$actor['id'] ?? null, $actor['actor_class'] ?? null, $actor['state'] ?? null, $actor['version'] ?? null, array_key_exists('responsible_actor_id', $actor) ? $actor['responsible_actor_id'] : 'missing'])
        ->toBe([$operator, 'service', 'active', 2, null])
        ->and([$changeset['actor_id'] ?? null, $changeset['command'] ?? null, $changeset['surface'] ?? null, $changeset['issuer_kind'] ?? null])
        ->toBe([$operator, 'installation.genesis', 'maintenance', 'system'])
        ->and([$audit['changeset_id'] ?? null, $audit['actor_id'] ?? null, $audit['surface'] ?? null, $audit['aggregates'] ?? null])
        ->toBe([$changeset['changeset_id'] ?? null, $operator, 'maintenance', '{actor:'.(is_string($operator) ? $operator : '').'}'])
        ->and($superuser->table('installation')->value('changeset_id'))->toBe($changeset['changeset_id'] ?? null)
        ->and($events)->toBe(['actor.registered', 'actor.activated']);

    [$again, $againOutput] = install();

    expect($again)->toBe(0, $againOutput)
        ->and($againOutput)->toContain('The installation operator is '.(is_string($operator) ? $operator : '').'. Nothing changed.')
        ->and(installationRows())->toBe($after);
});

it('refuses a process without the owner connection with install_owner_connection_required and writes nothing', function (): void {
    [$status, $output] = install(ownerConnection: false);

    expect($status)->toBe(78)
        ->and($output)->toContain('[install_owner_connection_required]')
        ->and(StorageTables::superuser()->table('installation')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('actors')->count())->toBe(0);
});

it('refuses a process that serves HTTP or runs queued jobs with the exit code of owner_credentials_exposed and writes nothing', function (array $environment, string $process): void {
    [$status, $output] = ProcessEnvironment::during($environment, static fn (): array => install());

    expect($status)->toBe(78)
        ->and($output)->toContain('[owner_credentials_exposed] cms:install runs as the owner role and only in the maintenance process, a console process, not a process that '.$process)
        ->and(StorageTables::superuser()->table('installation')->count())->toBe(0);
})->with([
    'an Octane worker' => [[ProcessWorkload::OCTANE_VARIABLE => '1'], 'serves HTTP'],
    'a queue worker' => [['argv' => ['artisan', 'queue:work']], 'runs queued jobs'],
]);

it('refuses an owner connection that logs in as the app role, which may not run the genesis', function (): void {
    config(['cbox-cms.database.owner_connection' => config()->string('database.default')]);

    $artisan = app(Kernel::class);
    $clock = new FakeClock(new DateTimeImmutable(INSTALL_NOW));
    app()->instance(Clock::class, $clock);
    $status = $artisan->call('cms:install');

    expect($status)->toBe(78)
        ->and($artisan->output())->toContain('[install_owner_connection_required] The connection ['.config()->string('database.default').'] may not create the installation operator')
        ->and(StorageTables::superuser()->table('installation')->count())->toBe(0);
});

it('refuses a time no partition covers with partition_missing and writes nothing', function (): void {
    [$status, $output] = install(covered: false);

    expect($status)->toBe(75)
        ->and($output)->toContain('[partition_missing]')
        ->and($output)->toContain('Run cms:partitions:maintain on the owner connection, then cms:install again.')
        ->and(StorageTables::superuser()->table('installation')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('actors')->count())->toBe(0);
});
