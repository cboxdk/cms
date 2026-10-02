<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Events\Infrastructure\EventReader;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Tests\Doctor\DoctorSchema;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Cbox\Cms\Core\Tests\Fragments\InvalidationWorld;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Cbox\Cms\Tests\Support\Phpstan;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * cms:doctor against the real services from compose.yaml (PRD 3.3, 4.2, 13.2): Postgres 18 as the
 * app role, Valkey, the partitions of this checkout's test database (cms_test_<hash of the
 * checkout's path>), the registry cache and the Node toolchain. Some tests run it in-process with a
 * FakeClock; the others run vendor/bin/testbench cms:doctor as a developer or a deploy script
 * would, with the environment changed for the case and DB_DATABASE set to that database.
 *
 * The runtime contract turns allow_url_fopen off (php.allow_url_fopen). It is a php.ini setting,
 * which a running process cannot change, so the in-process runs fake that one probe, and the
 * command-line runs start PHP with -d allow_url_fopen=0, or =1 for the test of the check.
 */

afterEach(function (): void {
    $scratch = RegistryScratch::$directory;

    if ($scratch !== null) {
        new Filesystem()->deleteDirectory($scratch);
        RegistryScratch::$directory = null;
    }
});

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
    app()->instance(PhpSettingsProbe::class, new FakePhpSettingsProbe(allowUrlFopen: false));
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:doctor', ['--json' => true, ...$options]);

    return [$status, validDoctorDocument($artisan->output())];
}

/**
 * Runs vendor/bin/testbench cms:doctor --json with changes to the environment, on PHP with
 * allow_url_fopen as the runtime contract sets it unless the test says otherwise.
 *
 * @param  list<string>  $options
 * @param  array<string, string>  $environment
 * @return array{int, array<string, mixed>, string}
 */
function testbenchDoctor(array $options = [], array $environment = [], string $allowUrlFopen = '0'): array
{
    $environment += ['DB_DATABASE' => CheckoutDatabase::name()];
    $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen='.$allowUrlFopen, 'vendor/bin/testbench', 'cms:doctor', '--json', ...$options], Phpstan::root(), $environment, null, 120);
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
        ->and(doctorStatuses($document))->toHaveCount(23)
        ->and(doctorCheck($document, 'postgres.transaction_timeout')['explanation'])->toBe('transaction_timeout is 5000 ms on the app role cms_app.')
        ->and(doctorCheck($document, 'postgres.lc_messages')['explanation'])->toBe('Messages are English: lc_messages is C for the role cms_app and C for the role cms_owner, and LC_MESSAGES of the PHP process is C.')
        ->and(doctorCheck($document, 'postgres.ddl_privileges')['explanation'])->toBe('The app role cms_app owns nothing and cannot create objects in the database '.CheckoutDatabase::name().' or its schemas.');
});

it('fails transaction_timeout, DDL and the app role for a role without the timeout that owns the database and the schema, with 78', function (): void {
    doctorClock('2047-06-10T09:00:00Z');
    buildRegistry();
    config(['cbox-cms.doctor.connection' => 'pgsql_owner']);

    [$status, $document] = inProcessDoctor();
    $timeout = doctorCheck($document, 'postgres.transaction_timeout');
    $ddl = doctorCheck($document, 'postgres.ddl_privileges');
    $appRole = doctorCheck($document, 'postgres.app_role');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($timeout['status'])->toBe('fail')
        ->and($timeout['failure'])->toBe('violation')
        ->and($timeout['code'])->toBe('doctor_transaction_timeout_missing')
        ->and($timeout['cause'])->toBe('transaction_timeout is 0 (off) for the role cms_owner; Postgres took the value from "default".')
        ->and($ddl['status'])->toBe('fail')
        ->and($ddl['cause'])->toContain('The role cms_owner: it owns ')
        ->and($ddl['cause'])->toContain('it has CREATE on the database '.CheckoutDatabase::name())
        ->and($ddl['cause'])->toContain('it has CREATE on the schemas cms, cms_identity, public')
        // The owner role owns the database, which makes it a member of pg_database_owner, the
        // owner of the schema public, and roles.sql makes it a member of pg_signal_backend.
        ->and($appRole['status'])->toBe('fail')
        ->and($appRole['code'])->toBe('doctor_app_role_privileged_membership')
        ->and($appRole['cause'])->toBe('The role cms_owner is a member of pg_database_owner, which owns or may create objects in the database or its schemas; pg_signal_backend, which cancels and terminates the sessions of every other non-superuser role, the owner\'s migrations and partition maintenance included.');
});

it('exits 79 with events.lag failing while an open transaction holds the horizon below an event of the critical lane', function (): void {
    $clock = doctorClock('2047-06-11T09:00:00Z');
    buildRegistry();
    $holder = app(IndependentConnections::class)->open(1)[0];

    try {
        // A transaction that took a transaction id before the event commits holds the horizon, so the
        // critical lane's runner cannot read the event and fragments.invalidate falls behind.
        $holder->beginTransaction();
        $holder->selectOne('select pg_current_xact_id()');
        [$position] = new CommittedEvents($clock)->write(EventStream::Interactive, [InvalidationWorld::created()]);

        expect(new EventReader(app('db'))->after(EventStream::Interactive, EventPosition::start(), 10))->toBe([]);

        $clock->advance(new DateInterval('PT2S'));
        [$status, $document] = inProcessDoctor();
        $lag = doctorCheck($document, 'events.lag');

        expect($status)->toBe(79)
            ->and($document['status'])->toBe('not_ready')
            ->and(array_filter(doctorStatuses($document), static fn (string $status): bool => $status !== 'pass'))->toBe(['events.lag' => 'fail'])
            ->and($lag['blocking'])->toBeFalse()
            ->and($lag['failure'])->toBe('violation')
            ->and($lag['code'])->toBe('doctor_events_lag')
            ->and($lag['cause'])->toBe('At 2047-06-11T09:00:02.000Z: fragments.invalidate (lane critical, target 500 ms) has not handled an event of the interactive stream from 2047-06-11T09:00:00.000Z, 2000 ms old.');

        $holder->rollBack();
    } finally {
        app(IndependentConnections::class)->closeAll();
    }

    // Once the runner has handled the event, the lane is within its target again.
    new PostgresSubscriptionLog(app('db'), $clock)->advance(new SubscriptionName('fragments.invalidate'), EventStream::Interactive, $position);

    expect(inProcessDoctor()[0])->toBe(0);
});

it('fails the partition runway with 79 when only 2 days of partitions exist, because it only affects readiness', function (): void {
    doctorClock('2047-03-10T12:00:00Z', 'P2D');
    buildRegistry();

    [$status, $document] = inProcessDoctor();
    $runway = doctorCheck($document, 'partitions.runway');

    expect($status)->toBe(79)
        ->and($document['status'])->toBe('not_ready')
        ->and(array_filter(doctorStatuses($document), static fn (string $status): bool => $status !== 'pass'))->toBe(['partitions.runway' => 'fail'])
        ->and($runway['status'])->toBe('fail')
        ->and($runway['blocking'])->toBeFalse()
        ->and($runway['failure'])->toBe('violation')
        ->and($runway['code'])->toBe('doctor_partition_runway_short')
        ->and($runway['cause'])->toBe('At 2047-03-10T12:00:00Z: receipts_standard until 2047-03-13T00:00:00Z (2.5 days), receipt_projections_standard until 2047-03-13T00:00:00Z (2.5 days), idempotency_keys until 2047-03-13T00:00:00Z (2.5 days), changesets until 2047-03-13T00:00:00Z (2.5 days), changeset_principals until 2047-03-13T00:00:00Z (2.5 days), audit until 2047-03-13T00:00:00Z (2.5 days), read_audit until 2047-03-13T00:00:00Z (2.5 days).');

    doctorClock('2047-03-10T12:00:00Z');

    expect(inProcessDoctor()[0])->toBe(0);
});

it('ends the runway at a gap in the partitions, not at the last partition', function (): void {
    $clock = doctorClock('2047-09-10T12:00:00Z', 'P2D');
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2047-09-16T00:00:00Z'), new DateTimeImmutable('2047-10-10T00:00:00Z'));
    buildRegistry();

    [$status, $document] = inProcessDoctor();
    $runway = doctorCheck($document, 'partitions.runway');

    expect($status)->toBe(79)
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
        'cbox-cms.doctor.connection' => 'pgsql_wrong_password',
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
        'cbox-cms.doctor.redis_connection' => 'doctor_closed',
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

it('exits 78 from the command line when PHP runs with allow_url_fopen on, and passes it off', function (): void {
    $now = new DateTimeImmutable('now');
    app(PartitionFixtures::class)->cover($now->sub(new DateInterval('P1D')), $now->add(new DateInterval('P14D')));
    buildRegistry();

    [$status, $document, $errors] = testbenchDoctor([], [], '1');
    $setting = doctorCheck($document, 'php.allow_url_fopen');
    [$offStatus, $off] = testbenchDoctor([], [], 'Off');

    expect($status)->toBe(78, $errors)
        ->and($document['status'])->toBe('violation')
        ->and($setting['status'])->toBe('fail')
        ->and($setting['blocking'])->toBeTrue()
        ->and($setting['code'])->toBe('doctor_php_allow_url_fopen')
        ->and(array_filter(doctorStatuses($document), static fn (string $status): bool => $status !== 'pass'))->toBe(['php.allow_url_fopen' => 'fail'])
        ->and($offStatus)->toBe(0)
        ->and(doctorCheck($off, 'php.allow_url_fopen')['status'])->toBe('pass');
});

it('reports node as failing with --dev when PATH lacks it, and does not check node without --dev', function (): void {
    buildRegistry();
    $environment = ['PATH' => '/usr/bin:/bin'];

    [$devStatus, $dev, $errors] = testbenchDoctor(['--dev'], $environment);
    [, $runtime] = testbenchDoctor([], $environment);
    $node = doctorCheck($dev, 'dev.node');

    expect($node['status'])->toBe('fail')
        ->and($node['code'])->toBe('doctor_node_missing')
        ->and($node['cause'])->toBe('There is no node on PATH (/usr/bin:/bin).')
        ->and(doctorStatuses($dev)['dev.playwright'])->toBe('skip')
        ->and($dev['dev'])->toBeTrue()
        ->and($dev['status'])->toBe('not_ready')
        ->and($devStatus)->toBe(79, $errors)
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
        ->and(doctorStatuses($document))->toHaveCount(26)
        ->and(doctorStatuses($document)['postgres.lc_messages'])->toBe('pass')
        ->and(array_unique(doctorStatuses($document)))->toBe(['php.version' => 'pass'])
        ->and(array_slice(array_keys(doctorStatuses($document)), -3))->toBe(['dev.node', 'dev.playwright', 'dev.chromium']);
});
