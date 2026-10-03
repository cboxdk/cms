<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\DoctorCommand;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Actions\RunDoctor;
use Cbox\Cms\Core\Doctor\Boundary\DoctorReportJson;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Cbox\Cms\Core\Tests\Doctor\Fakes\AddonReadyCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\AddonToolCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
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
        ->and(checkStatuses($document))->toHaveCount(27)
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

it('exits 79 when the owner connection is configured in a process that is not the maintenance process, which only affects readiness', function (): void {
    new DoctorFakes;
    // Its environment does not declare it, and a configuration that every process may share
    // through one cache declares nothing, so the old key cbox-cms.doctor.maintenance_process is
    // not read.
    config(['cbox-cms.doctor.maintenance_process' => true]);

    [$status, $document] = ProcessEnvironment::during(['CBOX_CMS_MAINTENANCE_PROCESS' => null], static fn (): array => doctorJson());
    $credentials = checkOf($document, 'postgres.owner_credentials');

    expect($status)->toBe(79)
        ->and($document['status'])->toBe('not_ready')
        ->and($document['exit_code'])->toBe(79)
        ->and($credentials['failure'])->toBe('violation')
        ->and($credentials['status'])->toBe('fail')
        ->and($credentials['blocking'])->toBeFalse()
        ->and($credentials['code'])->toBe('doctor_owner_credentials_exposed')
        ->and($credentials['cause'])->toBe('The owner connection pgsql_owner is configured in this process, and CBOX_CMS_MAINTENANCE_PROCESS in its environment does not declare it the maintenance process, so it may be a web or queue process that shares the maintenance process\'s configuration.')
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
            'postgres.idle_in_transaction_timeout' => 'skip',
            'postgres.prepared_transactions' => 'skip',
            'postgres.lc_messages' => 'skip',
            'postgres.ddl_privileges' => 'skip',
            'postgres.row_security' => 'skip',
            'postgres.extensions' => 'skip',
            'postgres.oldest_xact' => 'skip',
            'partitions.runway' => 'skip',
            'events.lag' => 'skip',
            'events.parked' => 'skip',
            'identity.operator_actor' => 'skip',
            'identity.connection' => 'skip',
            'identity.credential_isolation' => 'skip',
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

it('exits 79 for a short runway, which only affects readiness', function (): void {
    $fakes = new DoctorFakes;
    $fakes->partitions->runways = [new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-12T00:00:00Z'))];

    [$status, $output] = doctor();

    expect($status)->toBe(79)
        ->and($output)->toContain(' FAIL  partitions.runway ')
        ->and($output)->toContain('code   doctor_partition_runway_short (violation, affects readiness only)')
        ->and($output)->toContain('cause  At 2026-03-10T12:00:00Z: receipts_standard until 2026-03-12T00:00:00Z (1.5 days).')
        ->and($output)->toContain('fix    Run php artisan cms:partitions:maintain')
        ->and($output)->toContain('cms:doctor: not_ready (exit 79). The kernel may start, but is not ready until the checks that affect readiness pass.')
        ->and($fakes->log->records)->toBe([['warning', 'cms:doctor found problems.', [
            'status' => 'not_ready',
            'exit_code' => 79,
            'dev' => false,
            'failed' => ['partitions.runway doctor_partition_runway_short'],
        ]]]);
});

it('exits 75 when a blocking check cannot reach its dependency and a readiness check is violated', function (): void {
    $fakes = new DoctorFakes;
    $fakes->valkey->failure = ProbeFailed::unavailable('Connection refused');
    $fakes->partitions->runways = [new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-12T00:00:00Z'))];

    [$status, $document] = doctorJson();

    expect($status)->toBe(75)
        ->and($document['status'])->toBe('unavailable')
        ->and($document['exit_code'])->toBe(75)
        ->and(checkOf($document, 'valkey.reachable')['failure'])->toBe('unavailable')
        ->and(checkOf($document, 'valkey.reachable')['blocking'])->toBeTrue()
        ->and(checkOf($document, 'partitions.runway')['failure'])->toBe('violation')
        ->and(checkOf($document, 'partitions.runway')['blocking'])->toBeFalse();
});

it('exits 78 when a blocking check is violated, whatever fails that only affects readiness', function (): void {
    $fakes = new DoctorFakes;
    $fakes->valkey->failure = ProbeFailed::unavailable('Connection refused');
    $fakes->lcMessages->ownerRole = 'de_DE.UTF-8';
    $fakes->partitions->runways = [new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-12T00:00:00Z'))];

    [$status, $document] = ProcessEnvironment::during(['CBOX_CMS_MAINTENANCE_PROCESS' => 'false'], static fn (): array => doctorJson());

    expect($status)->toBe(78)
        ->and($document['status'])->toBe('violation')
        ->and(array_filter(checkStatuses($document), static fn (string $status): bool => $status === 'fail'))->toBe([
            'postgres.lc_messages' => 'fail',
            'valkey.reachable' => 'fail',
            'partitions.runway' => 'fail',
            'postgres.owner_credentials' => 'fail',
        ]);
});

it('adds the development checks with --dev and asks for no tool without it', function (): void {
    $fakes = new DoctorFakes;
    $fakes->tools->node = null;

    [$runtimeStatus, $runtime] = doctorJson();
    [$devStatus, $dev] = doctorJson(['--dev' => true]);

    expect($runtimeStatus)->toBe(0)
        ->and(array_keys(checkStatuses($runtime)))->not->toContain('dev.node')
        ->and($devStatus)->toBe(79)
        ->and($dev['status'])->toBe('not_ready')
        ->and($dev['dev'])->toBeTrue()
        ->and(array_slice(checkStatuses($dev), -3))->toBe(['dev.node' => 'fail', 'dev.playwright' => 'skip', 'dev.chromium' => 'skip'])
        ->and(checkOf($dev, 'dev.node')['code'])->toBe('doctor_node_missing')
        ->and(checkOf($dev, 'dev.node')['blocking'])->toBeFalse();
});

it('runs the checks an application or addon names in cbox-cms.doctor.checks and dev_checks after the core\'s', function (): void {
    new DoctorFakes;
    config(['cbox-cms.doctor.checks' => [AddonReadyCheck::class], 'cbox-cms.doctor.dev_checks' => [AddonToolCheck::class]]);

    [$runtimeStatus, $runtime] = doctorJson();
    [$devStatus, $dev] = doctorJson(['--dev' => true]);

    expect($runtimeStatus)->toBe(0)
        ->and(checkStatuses($runtime))->toHaveCount(22)
        ->and(array_slice(checkStatuses($runtime), -2))->toBe(['identity.operator_actor' => 'pass', 'addon.ready' => 'pass'])
        ->and(checkOf($runtime, 'addon.ready')['blocking'])->toBeFalse()
        ->and(checkOf($runtime, 'addon.ready')['explanation'])->toBe('The fixed check addon.ready passes.')
        ->and($devStatus)->toBe(0)
        ->and(array_slice(checkStatuses($dev), -5))->toBe([
            'addon.ready' => 'pass',
            'dev.node' => 'pass',
            'dev.playwright' => 'pass',
            'dev.chromium' => 'pass',
            'addon.tool' => 'pass',
        ]);
});

it('skips an added check whose requirement fails, and counts the failure of an added check in the exit code', function (): void {
    $fakes = new DoctorFakes;
    $fakes->postgres->connectFailure = ProbeFailed::unavailable('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused');
    app()->instance(FakeDoctorCheck::class, FakeDoctorCheck::failing(new CheckId('addon.settings'), FailureKind::Violation, blocking: false));
    config(['cbox-cms.doctor.checks' => [AddonReadyCheck::class, FakeDoctorCheck::class]]);

    [$status, $document] = doctorJson();

    expect($status)->toBe(75)
        ->and($document['status'])->toBe('unavailable')
        ->and(checkOf($document, 'addon.ready')['status'])->toBe('skip')
        ->and(checkOf($document, 'addon.ready')['cause'])->toBe('postgres.reachable did not pass.')
        ->and(checkOf($document, 'addon.settings')['status'])->toBe('fail')
        ->and(checkOf($document, 'addon.settings')['code'])->toBe(FakeDoctorCheck::CODE);

    $fakes->postgres->connectFailure = null;

    [$readyStatus, $ready] = doctorJson();

    expect($readyStatus)->toBe(79)
        ->and($ready['status'])->toBe('not_ready')
        ->and(checkOf($ready, 'addon.ready')['status'])->toBe('pass')
        ->and(checkOf($ready, 'addon.settings')['status'])->toBe('fail');

    app()->instance(FakeDoctorCheck::class, FakeDoctorCheck::failing(new CheckId('addon.settings'), FailureKind::Violation));

    expect(doctorJson()[0])->toBe(78);
});

it('reports an added check that cannot be used as the failing check doctor.config', function (): void {
    new DoctorFakes;
    config(['cbox-cms.doctor.dev_checks' => [AddonReadyCheck::class, AddonReadyCheck::class]]);

    [$status, $document] = doctorJson(['--dev' => true]);

    expect($status)->toBe(78)
        ->and(checkStatuses($document))->toBe(['doctor.config' => 'fail'])
        ->and(checkOf($document, 'doctor.config')['cause'])->toBe('The checks in cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks cannot run after the core\'s checks: The check "addon.ready" is listed twice. Every check has its own id.');
});

it('reports invalid settings as the failing check doctor.config', function (): void {
    new DoctorFakes;
    config(['cbox-cms.doctor.connect_timeout_seconds' => 'soon']);

    [$status, $document] = doctorJson();

    expect($status)->toBe(78)
        ->and(checkStatuses($document))->toBe(['doctor.config' => 'fail'])
        ->and(checkOf($document, 'doctor.config')['cause'])->toBe("The setting cbox-cms.doctor.connect_timeout_seconds must be a whole number of at least 1; it is 'soon'.");
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
        ->and($width)->toBe(strlen('postgres.idle_in_transaction_timeout'))
        ->and(explode("\n", $output))->toBe([...$expected, '', 'cms:doctor: violation (exit 78). The kernel may not start.', ''])
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
