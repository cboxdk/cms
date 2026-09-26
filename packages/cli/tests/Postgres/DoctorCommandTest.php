<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Doctor\DoctorSchema;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * cms:doctor against the real services from compose.yaml (PRD 3.3, 4.2, 13.2): Postgres 18 as the
 * app role, Valkey, the partitions of cms_test, the registry cache and the Node toolchain. Some
 * tests run it in-process with a FakeClock; the others run vendor/bin/testbench cms:doctor as a
 * developer or a deploy script would, with the environment changed for the case.
 */

afterEach(function (): void {
    $scratch = RegistryScratch::$directory;

    if ($scratch !== null) {
        new Filesystem()->deleteDirectory($scratch);
        RegistryScratch::$directory = null;
    }
});

/**
 * A registry cache and vendor manifest in a temporary directory, so a test can age them without
 * touching the application's own.
 */
final class RegistryScratch
{
    public static ?string $directory = null;

    public static function create(): string
    {
        $directory = sys_get_temp_dir().'/cms-doctor-'.bin2hex(random_bytes(6));
        mkdir($directory.'/cache', 0o775, true);
        mkdir($directory.'/vendor/composer', 0o775, true);
        file_put_contents($directory.'/vendor/composer/installed.json', '{"packages":[]}');
        self::$directory = $directory;

        app()->instance(RegistryCache::class, new FileRegistryCache($directory.'/cache', new RegistryCacheCodec));
        config(['cms.doctor.vendor_manifest' => $directory.'/vendor/composer/installed.json']);

        return $directory;
    }
}

/**
 * A FakeClock at a date no other test writes at, with partitions for the runway ahead of it.
 */
function doctorClock(string $at, string $ahead = 'P14D'): FakeClock
{
    $clock = new FakeClock(new DateTimeImmutable($at));
    app()->instance(Clock::class, $clock);
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval($ahead));

    return $clock;
}

/**
 * Runs cms:doctor --json in-process and decodes the document after checking it against the schema.
 *
 * @param  array<string, bool>  $options
 * @return array{int, array<string, mixed>}
 */
function inProcessDoctor(array $options = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:doctor', ['--json' => true, ...$options]);

    return [$status, validDoctorDocument($artisan->output())];
}

/**
 * Runs vendor/bin/testbench cms:doctor --json with changes to the environment.
 *
 * @param  list<string>  $options
 * @param  array<string, string>  $environment
 * @return array{int, array<string, mixed>, string}
 */
function testbenchDoctor(array $options = [], array $environment = []): array
{
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'cms:doctor', '--json', ...$options], Phpstan::root(), $environment, null, 120);
    $process->run();

    return [(int) $process->getExitCode(), validDoctorDocument($process->getOutput()), $process->getErrorOutput()];
}

/**
 * @return array<string, mixed>
 */
function validDoctorDocument(string $json): array
{
    expect(DoctorSchema::errors($json))->toBe([], $json);

    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    return $document;
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function doctorCheck(array $document, string $id): array
{
    foreach (is_array($document['checks'] ?? null) ? $document['checks'] : [] as $check) {
        if (is_array($check) && ($check['id'] ?? null) === $id) {
            /** @var array<string, mixed> $check */
            return $check;
        }
    }

    throw new UnexpectedValueException("The document has no check {$id}.");
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, string>
 */
function doctorStatuses(array $document): array
{
    $statuses = [];

    foreach (is_array($document['checks'] ?? null) ? $document['checks'] : [] as $check) {
        if (is_array($check) && is_string($check['id'] ?? null) && is_string($check['status'] ?? null)) {
            $statuses[$check['id']] = $check['status'];
        }
    }

    return $statuses;
}

/**
 * Builds the application's registry cache, which the testbench processes read.
 */
function buildRegistry(): void
{
    expect(app(Kernel::class)->call('cms:build'))->toBe(0);
}

it('passes every runtime check against the services, in-process', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    buildRegistry();

    [$status, $document] = inProcessDoctor();

    expect($status)->toBe(0, (string) json_encode($document))
        ->and($document['status'])->toBe('ok')
        ->and(array_unique(doctorStatuses($document)))->toBe(['php.version' => 'pass'])
        ->and(doctorStatuses($document))->toHaveCount(11)
        ->and(doctorCheck($document, 'postgres.transaction_timeout')['explanation'])->toBe('transaction_timeout is 5000 ms on the app role cms_app.')
        ->and(doctorCheck($document, 'postgres.ddl_privileges')['explanation'])->toBe('The app role cms_app owns nothing and cannot create objects in the database cms_test or its schemas.');
});

it('fails transaction_timeout and DDL for a role without the timeout that owns the schema, with 78', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    buildRegistry();
    config(['cms.doctor.connection' => 'pgsql_owner']);

    [$status, $document] = inProcessDoctor();
    $timeout = doctorCheck($document, 'postgres.transaction_timeout');
    $ddl = doctorCheck($document, 'postgres.ddl_privileges');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($timeout['status'])->toBe('fail')
        ->and($timeout['failure'])->toBe('violation')
        ->and($timeout['code'])->toBe('doctor_transaction_timeout_missing')
        ->and($timeout['cause'])->toBe('transaction_timeout is 0 (off) for the role cms_owner; Postgres took the value from "default".')
        ->and($ddl['status'])->toBe('fail')
        ->and($ddl['cause'])->toContain('The role cms_owner: it owns ')
        ->and($ddl['cause'])->toContain('it has CREATE on the database cms_test')
        ->and($ddl['cause'])->toContain('it has CREATE on the schemas cms, public')
        ->and(doctorCheck($document, 'postgres.app_role')['status'])->toBe('pass');
});

it('fails the partition runway with 78 when only 2 days of partitions exist', function (): void {
    doctorClock('2047-03-10T12:00:00Z', 'P2D');
    buildRegistry();

    [$status, $document] = inProcessDoctor();
    $runway = doctorCheck($document, 'partitions.runway');

    expect($status)->toBe(78)
        ->and($runway['status'])->toBe('fail')
        ->and($runway['blocking'])->toBeFalse()
        ->and($runway['failure'])->toBe('violation')
        ->and($runway['code'])->toBe('doctor_partition_runway_short')
        ->and($runway['cause'])->toBe('At 2047-03-10T12:00:00Z: receipts_standard until 2047-03-13T00:00:00Z (2.5 days), receipt_projections_standard until 2047-03-13T00:00:00Z (2.5 days), idempotency_keys until 2047-03-13T00:00:00Z (2.5 days).');

    doctorClock('2047-03-10T12:00:00Z');

    expect(inProcessDoctor()[0])->toBe(0);
});

it('ends the runway at a gap in the partitions, not at the last partition', function (): void {
    $clock = doctorClock('2047-09-10T12:00:00Z', 'P2D');
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2047-09-16T00:00:00Z'), new DateTimeImmutable('2047-10-10T00:00:00Z'));
    buildRegistry();

    [$status, $document] = inProcessDoctor();
    $runway = doctorCheck($document, 'partitions.runway');

    expect($status)->toBe(78)
        ->and($runway['cause'])->toContain('receipts_standard until 2047-09-13T00:00:00Z (2.5 days)')
        ->and($runway['cause'])->not->toContain('receipts_evidence');

    app(PartitionFixtures::class)->cover($clock->now(), new DateTimeImmutable('2047-09-16T00:00:00Z'));

    expect(inProcessDoctor()[0])->toBe(0);
});

it('reports a stale registry cache after installed.json changes, and passes after cms:build', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    $scratch = RegistryScratch::create();
    buildRegistry();

    $manifest = $scratch.'/vendor/composer/installed.json';
    $now = time();
    touch($manifest, $now - 60);

    expect(inProcessDoctor()[0])->toBe(0);

    foreach (glob($scratch.'/cache/*.php') ?: [] as $file) {
        touch($file, $now - 30);
    }

    touch($manifest, $now);

    [$status, $document] = inProcessDoctor();
    $registry = doctorCheck($document, 'registry.cache');

    expect($status)->not->toBe(0)
        ->and($status)->toBe(78)
        ->and($registry['status'])->toBe('fail')
        ->and($registry['code'])->toBe('doctor_registry_cache_stale')
        ->and($registry['blocking'])->toBeTrue()
        ->and($registry['fix'])->toContain('php artisan cms:build');

    buildRegistry();

    expect(inProcessDoctor()[0])->toBe(0);
});

it('reports a Postgres that refuses the login as a violation', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    buildRegistry();
    config([
        'database.connections.pgsql_wrong_password' => array_merge((array) config('database.connections.pgsql'), ['password' => 'not-the-password']),
        'cms.doctor.connection' => 'pgsql_wrong_password',
    ]);

    [$status, $document] = inProcessDoctor();
    $postgres = doctorCheck($document, 'postgres.reachable');

    expect($status)->toBe(78)
        ->and($postgres['code'])->toBe('doctor_postgres_refused')
        ->and($postgres['cause'])->toContain('password authentication failed for user "cms_app"')
        ->and(doctorStatuses($document)['postgres.version'])->toBe('skip');
});

it('reports a Valkey that cannot be reached as unavailable with 75', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    buildRegistry();
    config([
        'database.redis.doctor_closed' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 15],
        'cms.doctor.redis_connection' => 'doctor_closed',
    ]);

    $started = hrtime(true);
    [$status, $document] = inProcessDoctor();
    $valkey = doctorCheck($document, 'valkey.reachable');

    expect($status)->toBe(75)
        ->and($document['status'])->toBe('unavailable')
        ->and($valkey['failure'])->toBe('unavailable')
        ->and($valkey['code'])->toBe('doctor_valkey_unavailable')
        ->and($valkey['explanation'])->toContain('127.0.0.1:1 (Redis connection doctor_closed)')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(10.0);
});

it('exits with 75 from the command line when DB_PORT points at a closed port', function (): void {
    buildRegistry();

    $started = hrtime(true);
    [$status, $document, $errors] = testbenchDoctor([], ['DB_PORT' => '1']);
    $postgres = doctorCheck($document, 'postgres.reachable');

    expect($status)->toBe(75, $errors)
        ->and($document['status'])->toBe('unavailable')
        ->and($document['exit_code'])->toBe(75)
        ->and($postgres['status'])->toBe('fail')
        ->and($postgres['failure'])->toBe('unavailable')
        ->and($postgres['cause'])->toContain('port 1 failed')
        ->and($postgres['fix'])->toBeString()->toContain('DB_HOST and DB_PORT')
        ->and(doctorStatuses($document)['partitions.runway'])->toBe('skip')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(30.0);
});

it('reports node as failing with --dev when PATH lacks it, and does not check node without --dev', function (): void {
    buildRegistry();
    $environment = ['PATH' => '/usr/bin:/bin'];

    [, $dev] = testbenchDoctor(['--dev'], $environment);
    [, $runtime] = testbenchDoctor([], $environment);
    $node = doctorCheck($dev, 'dev.node');

    expect($node['status'])->toBe('fail')
        ->and($node['code'])->toBe('doctor_node_missing')
        ->and($node['cause'])->toBe('There is no node on PATH (/usr/bin:/bin).')
        ->and(doctorStatuses($dev)['dev.playwright'])->toBe('skip')
        ->and($dev['dev'])->toBeTrue()
        ->and($dev['status'])->toBe('violation')
        ->and(array_keys(doctorStatuses($runtime)))->not->toContain('dev.node')
        ->and($runtime['dev'])->toBeFalse();
});

it('exits 0 from the command line with --dev --json when the services, partitions, registry and toolchain are in place', function (): void {
    $now = new DateTimeImmutable('now');
    app(PartitionFixtures::class)->cover($now->sub(new DateInterval('P1D')), $now->add(new DateInterval('P14D')));
    buildRegistry();

    [$status, $document, $errors] = testbenchDoctor(['--dev']);

    expect($status)->toBe(0, $errors.json_encode($document))
        ->and($document['status'])->toBe('ok')
        ->and($document['dev'])->toBeTrue()
        ->and(doctorStatuses($document))->toHaveCount(14)
        ->and(array_unique(doctorStatuses($document)))->toBe(['php.version' => 'pass'])
        ->and(array_slice(array_keys(doctorStatuses($document)), -3))->toBe(['dev.node', 'dev.playwright', 'dev.chromium']);
});
