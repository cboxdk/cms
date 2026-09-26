<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\DoctorCommand;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePartitionRunwayProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRuntimeProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeValkeyProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use UnexpectedValueException;

/*
 * cms:doctor in the testbench application with fake probes behind the real checks: the exit
 * codes as numbers, the JSON document, the human output, --dev, and the log line.
 */

/**
 * Keeps what was logged.
 */
final class FakeLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}

/**
 * The fake probes bound for one test, all healthy until the test changes one.
 */
final class DoctorFakes
{
    public FakeRuntimeProbe $runtime;

    public FakePostgresProbe $postgres;

    public FakeValkeyProbe $valkey;

    public FakePartitionRunwayProbe $partitions;

    public FakeRegistryCacheProbe $registry;

    public FakeToolProbe $tools;

    public FakeLogger $log;

    public function __construct()
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));

        $this->runtime = new FakeRuntimeProbe;
        $this->postgres = new FakePostgresProbe;
        $this->valkey = new FakeValkeyProbe;
        $this->partitions = new FakePartitionRunwayProbe([new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-24T00:00:00Z'))]);
        $this->registry = new FakeRegistryCacheProbe;
        $this->tools = new FakeToolProbe;

        app()->instance(Clock::class, $clock);
        app()->instance(RuntimeProbe::class, $this->runtime);
        app()->instance(PostgresProbe::class, $this->postgres);
        app()->instance(ValkeyProbe::class, $this->valkey);
        app()->instance(PartitionRunwayProbe::class, $this->partitions);
        app()->instance(RegistryCacheProbe::class, $this->registry);
        app()->instance(ToolProbe::class, $this->tools);

        $this->log = new FakeLogger;
        app()->instance(LoggerInterface::class, $this->log);
    }
}

/**
 * Runs cms:doctor and returns its exit code and output.
 *
 * @param  array<string, bool>  $options
 * @return array{int, string}
 */
function doctor(array $options = []): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:doctor', $options);

    return [$status, $artisan->output()];
}

/**
 * Runs cms:doctor --json and decodes the document.
 *
 * @param  array<string, bool>  $options
 * @return array{int, array<string, mixed>}
 */
function doctorJson(array $options = []): array
{
    [$status, $output] = doctor(['--json' => true, ...$options]);
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    return [$status, $document];
}

/**
 * The status of each check in a document, by id.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, string>
 */
function checkStatuses(array $document): array
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
 * One check of a document.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function checkOf(array $document, string $id): array
{
    foreach (is_array($document['checks'] ?? null) ? $document['checks'] : [] as $check) {
        if (is_array($check) && ($check['id'] ?? null) === $id) {
            /** @var array<string, mixed> $check */
            return $check;
        }
    }

    throw new UnexpectedValueException("The document has no check {$id}.");
}

it('is registered', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:doctor')
        ->and(app(Kernel::class)->all()['cms:doctor'])->toBeInstanceOf(DoctorCommand::class);
});

it('exits 0 and prints every runtime check when nothing is wrong', function (): void {
    $fakes = new DoctorFakes;

    [$status, $output] = doctor();

    expect($status)->toBe(0)
        ->and($output)->toContain(' pass  php.version ')
        ->and($output)->toContain(' pass  registry.cache ')
        ->and($output)->not->toContain('dev.node')
        ->and($output)->toContain('cms:doctor: ok (exit 0).')
        ->and($fakes->log->records)->toBe([['info', 'cms:doctor found nothing wrong.', ['status' => 'ok', 'exit_code' => 0, 'dev' => false, 'failed' => []]]]);
});

it('prints the JSON document and nothing else with --json', function (): void {
    new DoctorFakes;

    [$status, $document] = doctorJson();

    expect($status)->toBe(0)
        ->and(array_keys($document))->toBe(['checks', 'dev', 'exit_code', 'status', 'version'])
        ->and($document['version'])->toBe(1)
        ->and($document['dev'])->toBeFalse()
        ->and($document['status'])->toBe('ok')
        ->and($document['exit_code'])->toBe(0)
        ->and(checkStatuses($document))->toHaveCount(11)
        ->and(array_keys(checkOf($document, 'php.version')))->toBe(['blocking', 'cause', 'code', 'explanation', 'failure', 'fix', 'id', 'status']);
});

it('exits 78 when a fake version probe says Postgres 16, and skips what needs 17', function (): void {
    $fakes = new DoctorFakes;
    $fakes->postgres->versionNumber = 160_004;
    $fakes->postgres->versionText = '16.4';

    [$status, $document] = doctorJson();
    $version = checkOf($document, 'postgres.version');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($document['exit_code'])->toBe(78)
        ->and($version['status'])->toBe('fail')
        ->and($version['failure'])->toBe('violation')
        ->and($version['code'])->toBe('doctor_postgres_version')
        ->and($version['cause'])->toBe('The server runs Postgres 16.4 (server_version_num 160004).')
        ->and(checkStatuses($document)['postgres.transaction_timeout'])->toBe('skip')
        ->and(checkStatuses($document)['postgres.app_role'])->toBe('pass');
});

it('exits 75 when Postgres cannot be reached, and skips the checks that need it', function (): void {
    $fakes = new DoctorFakes;
    $fakes->postgres->connectFailure = ProbeFailed::unavailable('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused');

    [$status, $document] = doctorJson();
    $postgres = checkOf($document, 'postgres.reachable');

    expect($status)->toBe(75)
        ->and($document['status'])->toBe('unavailable')
        ->and($postgres['status'])->toBe('fail')
        ->and($postgres['failure'])->toBe('unavailable')
        ->and($postgres['fix'])->toBeString()->toContain('Start Postgres')
        ->and(array_filter(checkStatuses($document), static fn (string $status): bool => $status === 'skip'))->toBe([
            'postgres.version' => 'skip',
            'postgres.app_role' => 'skip',
            'postgres.transaction_timeout' => 'skip',
            'postgres.prepared_transactions' => 'skip',
            'postgres.ddl_privileges' => 'skip',
            'partitions.runway' => 'skip',
        ]);
});

it('exits 78 when a violation and an unavailable dependency come together', function (): void {
    $fakes = new DoctorFakes;
    $fakes->valkey->failure = ProbeFailed::unavailable('Connection refused');
    $fakes->registry->state = FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T09:00:00Z'));

    [$status, $document] = doctorJson();

    expect($status)->toBe(78)
        ->and(checkStatuses($document)['valkey.reachable'])->toBe('fail')
        ->and(checkStatuses($document)['registry.cache'])->toBe('fail');
});

it('exits 78 for a short runway, which only affects readiness', function (): void {
    $fakes = new DoctorFakes;
    $fakes->partitions->runways = [new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-12T00:00:00Z'))];

    [$status, $output] = doctor();

    expect($status)->toBe(78)
        ->and($output)->toContain(' FAIL  partitions.runway ')
        ->and($output)->toContain('code   doctor_partition_runway_short (violation, affects readiness only)')
        ->and($output)->toContain('cause  At 2026-03-10T12:00:00Z: receipts_standard until 2026-03-12T00:00:00Z (1.5 days).')
        ->and($output)->toContain('fix    Run php artisan cms:partitions:maintain')
        ->and($output)->toContain('cms:doctor: violation (exit 78).');
});

it('adds the development checks with --dev and asks for no tool without it', function (): void {
    $fakes = new DoctorFakes;
    $fakes->tools->node = null;

    [$runtimeStatus, $runtime] = doctorJson();
    [$devStatus, $dev] = doctorJson(['--dev' => true]);

    expect($runtimeStatus)->toBe(0)
        ->and(array_keys(checkStatuses($runtime)))->not->toContain('dev.node')
        ->and($devStatus)->toBe(78)
        ->and($dev['dev'])->toBeTrue()
        ->and(array_slice(checkStatuses($dev), -3))->toBe(['dev.node' => 'fail', 'dev.playwright' => 'skip', 'dev.chromium' => 'skip'])
        ->and(checkOf($dev, 'dev.node')['code'])->toBe('doctor_node_missing')
        ->and(checkOf($dev, 'dev.node')['blocking'])->toBeFalse();
});

it('reports invalid settings as the failing check doctor.config', function (): void {
    new DoctorFakes;
    config(['cms.doctor.connect_timeout_seconds' => 'soon']);

    [$status, $document] = doctorJson();

    expect($status)->toBe(78)
        ->and(checkStatuses($document))->toBe(['doctor.config' => 'fail'])
        ->and(checkOf($document, 'doctor.config')['cause'])->toBe("The setting cms.doctor.connect_timeout_seconds must be a whole number of at least 1; it is 'soon'.");
});

it('logs the failing checks with their codes', function (): void {
    $fakes = new DoctorFakes;
    $fakes->postgres->transactionTimeoutMs = 0;
    $fakes->postgres->transactionTimeoutSource = 'default';

    [$status] = doctor(['--json' => true]);

    expect($status)->toBe(78)
        ->and($fakes->log->records)->toBe([['warning', 'cms:doctor found problems.', [
            'status' => 'violation',
            'exit_code' => 78,
            'dev' => false,
            'failed' => ['postgres.transaction_timeout doctor_transaction_timeout_missing'],
        ]]]);
});
