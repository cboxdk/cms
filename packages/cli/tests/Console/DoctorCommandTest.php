<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\DoctorCommand;
use Cbox\Cms\Core\Doctor\Actions\RunDoctor;
use Cbox\Cms\Core\Doctor\Boundary\DoctorReportJson;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use UnexpectedValueException;

/*
 * cms:doctor in the testbench application with fake probes behind the real checks: the exit
 * codes as numbers, the JSON document, the human output, --dev, and the log line.
 */

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
        ->and(checkStatuses($document))->toHaveCount(15)
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

it('exits 78 when allow_url_fopen is on, and runs every other check', function (): void {
    $fakes = new DoctorFakes;
    $fakes->phpSettings->allowUrlFopen = true;

    [$status, $document] = doctorJson();
    $setting = checkOf($document, 'php.allow_url_fopen');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($setting['status'])->toBe('fail')
        ->and($setting['blocking'])->toBeTrue()
        ->and($setting['failure'])->toBe('violation')
        ->and($setting['code'])->toBe('doctor_php_allow_url_fopen')
        ->and($setting['fix'])->toBeString()->toContain('-d allow_url_fopen=0')
        ->and(array_filter(checkStatuses($document), static fn (string $status): bool => $status !== 'pass'))->toBe(['php.allow_url_fopen' => 'fail']);
});

it('exits 78 when the owner role writes its messages in German', function (): void {
    $fakes = new DoctorFakes;
    $fakes->lcMessages->ownerRole = 'de_DE.UTF-8';

    [$status, $document] = doctorJson();
    $messages = checkOf($document, 'postgres.lc_messages');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($messages['status'])->toBe('fail')
        ->and($messages['blocking'])->toBeTrue()
        ->and($messages['failure'])->toBe('violation')
        ->and($messages['code'])->toBe('doctor_lc_messages_not_english')
        ->and($messages['cause'])->toBe('lc_messages is \'de_DE.UTF-8\' for the role cms_owner, read on the connection pgsql; Postgres takes it from "user".')
        ->and($messages['fix'])->toBeString()->toContain("ALTER ROLE cms_owner SET lc_messages = 'C'");
});

it('exits 78 when the owner connection is configured in a process that is not the maintenance process', function (): void {
    new DoctorFakes;
    config(['cms.doctor.maintenance_process' => false]);

    [$status, $document] = doctorJson();
    $credentials = checkOf($document, 'postgres.owner_credentials');

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and($credentials['status'])->toBe('fail')
        ->and($credentials['blocking'])->toBeFalse()
        ->and($credentials['code'])->toBe('doctor_owner_credentials_exposed')
        ->and(array_filter(checkStatuses($document), static fn (string $status): bool => $status !== 'pass'))->toBe(['postgres.owner_credentials' => 'fail']);
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
            'postgres.lc_messages' => 'skip',
            'postgres.ddl_privileges' => 'skip',
            'postgres.row_security' => 'skip',
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
    $fakes->postgres->transactionTimeoutSource = SettingSource::Default;

    [$status] = doctor(['--json' => true]);

    expect($status)->toBe(78)
        ->and($fakes->log->records)->toBe([['warning', 'cms:doctor found problems.', [
            'status' => 'violation',
            'exit_code' => 78,
            'dev' => false,
            'failed' => ['postgres.transaction_timeout doctor_transaction_timeout_missing'],
        ]]]);
});

it('prints exactly the JSON document with --json, without a line break of its own', function (): void {
    new DoctorFakes;

    [, $output] = doctor(['--json' => true]);

    expect($output)->toBe(DoctorReportJson::encode(app(RunDoctor::class)->run(new DoctorRunOptions)));
});

it('lines up every check under the longest id and prints the cause, fix and code of a failure indented past the id', function (): void {
    $fakes = new DoctorFakes;
    $fakes->partitions->runways = [new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-12T00:00:00Z'))];
    $fakes->lcMessages->ownerRole = 'de_DE.UTF-8';

    [, $document] = doctorJson();
    [$status, $output] = doctor();
    $checks = is_array($document['checks'] ?? null) ? $document['checks'] : [];
    $ids = array_keys(checkStatuses($document));
    $width = max(0, ...array_map(strlen(...), $ids));
    $indent = str_repeat(' ', $width + 9);
    $expected = [];

    foreach ($checks as $check) {
        expect($check)->toBeArray();

        if (! is_array($check)) {
            continue;
        }

        $label = match ($check['status'] ?? null) {
            'pass' => 'pass',
            'fail' => 'FAIL',
            default => 'skip',
        };
        $id = is_string($check['id'] ?? null) ? $check['id'] : '';
        $explanation = is_string($check['explanation'] ?? null) ? $check['explanation'] : '';
        $expected[] = sprintf(' %s  %s  %s', $label, str_pad($id, $width), $explanation);

        if (is_string($check['cause'] ?? null)) {
            $expected[] = $indent.'cause  '.$check['cause'];
        }

        if (is_string($check['fix'] ?? null)) {
            $expected[] = $indent.'fix    '.$check['fix'];
        }

        if (is_string($check['code'] ?? null) && is_string($check['failure'] ?? null)) {
            $expected[] = sprintf('%scode   %s (%s, %s)', $indent, $check['code'], $check['failure'], $check['blocking'] === true ? 'blocks the kernel from starting' : 'affects readiness only');
        }
    }

    expect($status)->toBe(78)
        ->and($width)->toBe(strlen('postgres.prepared_transactions'))
        ->and(explode("\n", $output))->toBe([...$expected, '', 'cms:doctor: violation (exit 78).', ''])
        ->and($output)->toContain('code   doctor_lc_messages_not_english (violation, blocks the kernel from starting)', 'code   doctor_partition_runway_short (violation, affects readiness only)');
});

it('ends a clean run with the summary as information after an empty line', function (): void {
    new DoctorFakes;

    [$status, $output] = doctor();
    $lines = explode("\n", $output);

    expect($status)->toBe(0)
        ->and(array_slice($lines, -3))->toBe(['', 'cms:doctor: ok (exit 0).', ''])
        ->and($output)->not->toContain('cause  ');
});
