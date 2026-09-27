<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Actions\RunDoctor;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeDoctorChecks;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use RuntimeException;

/*
 * The doctor runs the checks in order, skips a check whose requirement did not pass, turns a
 * check that breaks its contract into a failure, and adds up the exit code. The action is called
 * directly with its options and the fake list of checks (GUARDRAILS 9); FakeDoctorChecks is held
 * to OrderedDoctorChecks by DoctorChecksBehaviour.
 */

function doctorId(string $id): CheckId
{
    return new CheckId($id);
}

/**
 * @return list<string>
 */
function statuses(RunDoctor $doctor, bool $dev = false): array
{
    return array_map(static fn (CheckResult $result): string => $result->id->value.' '.$result->status->value, $doctor->run(new DoctorRunOptions($dev))->results);
}

it('runs the runtime checks, and the dev checks after them with --dev', function (): void {
    $runtime = FakeDoctorCheck::passing(doctorId('fake.runtime'));
    $dev = FakeDoctorCheck::passing(doctorId('fake.dev'), false, [doctorId('fake.runtime')]);
    $doctor = new RunDoctor(new FakeDoctorChecks([$runtime], [$dev]));

    expect(statuses($doctor))->toBe(['fake.runtime pass'])
        ->and($dev->runs())->toBe(0)
        ->and(statuses($doctor, true))->toBe(['fake.runtime pass', 'fake.dev pass'])
        ->and($doctor->run(new DoctorRunOptions(dev: true))->dev)->toBeTrue()
        ->and($doctor->run(new DoctorRunOptions)->dev)->toBeFalse();
});

it('skips a check whose requirement did not pass, and names the requirement', function (): void {
    $postgres = FakeDoctorCheck::failing(doctorId('fake.postgres'), FailureKind::Unavailable);
    $roles = FakeDoctorCheck::passing(doctorId('fake.roles'), true, [doctorId('fake.postgres')]);
    $grants = FakeDoctorCheck::passing(doctorId('fake.grants'), false, [doctorId('fake.roles')]);
    $valkey = FakeDoctorCheck::passing(doctorId('fake.valkey'));
    $report = new RunDoctor(new FakeDoctorChecks([$postgres, $roles, $grants, $valkey], []))->run(new DoctorRunOptions);

    expect(array_map(static fn (CheckResult $result): string => $result->status->value, $report->results))->toBe(['fail', 'skip', 'skip', 'pass'])
        ->and($roles->runs())->toBe(0)
        ->and($grants->runs())->toBe(0)
        ->and($report->results[1]->cause)->toBe('fake.postgres did not pass.')
        ->and($report->results[1]->explanation)->toContain('needs fake.postgres to pass first')
        ->and($report->results[2]->cause)->toBe('fake.roles did not pass.')
        ->and($report->results[2]->blocking)->toBeFalse()
        ->and($report->exit)->toBe(DoctorExitCode::Unavailable);

    $postgres->passes();

    expect(statuses(new RunDoctor(new FakeDoctorChecks([$postgres, $roles, $grants, $valkey], []))))
        ->toBe(['fake.postgres pass', 'fake.roles pass', 'fake.grants pass', 'fake.valkey pass']);
});

/**
 * The exit code of one run of these checks.
 *
 * @param  list<DoctorCheck>  $runtime
 * @param  list<DoctorCheck>  $dev
 */
function exitOf(array $runtime, array $dev = [], bool $withDev = false): DoctorExitCode
{
    return new RunDoctor(new FakeDoctorChecks($runtime, $dev))->run(new DoctorRunOptions($withDev))->exit;
}

it('adds up the exit code: the blocking failures decide it, a violation wins, and a readiness failure alone gives 79', function (): void {
    $ok = FakeDoctorCheck::passing(doctorId('fake.ok'));
    $unavailable = FakeDoctorCheck::failing(doctorId('fake.valkey'), FailureKind::Unavailable);
    $violation = FakeDoctorCheck::failing(doctorId('fake.config'), FailureKind::Violation);
    $readiness = FakeDoctorCheck::failing(doctorId('fake.runway'), FailureKind::Violation, false);
    $workers = FakeDoctorCheck::failing(doctorId('fake.workers'), FailureKind::Unavailable, false);
    $tool = FakeDoctorCheck::failing(doctorId('fake.tool'), FailureKind::Unavailable, false);

    expect(exitOf([$ok]))->toBe(DoctorExitCode::Ok)
        ->and(exitOf([$ok])->value)->toBe(0)
        ->and(exitOf([$unavailable]))->toBe(DoctorExitCode::Unavailable)
        ->and(exitOf([$unavailable])->value)->toBe(75)
        ->and(exitOf([$violation, $unavailable]))->toBe(DoctorExitCode::Violation)
        ->and(exitOf([$unavailable, $violation])->value)->toBe(78)
        ->and(exitOf([$readiness]))->toBe(DoctorExitCode::NotReady)
        ->and(exitOf([$ok, $workers])->value)->toBe(79)
        ->and(exitOf([$readiness, $workers]))->toBe(DoctorExitCode::NotReady)
        ->and(exitOf([$unavailable, $readiness]))->toBe(DoctorExitCode::Unavailable)
        ->and(exitOf([$readiness, $unavailable, $workers]))->toBe(DoctorExitCode::Unavailable)
        ->and(exitOf([$readiness, $violation, $workers]))->toBe(DoctorExitCode::Violation)
        ->and(exitOf([$workers, $unavailable, $readiness, $violation]))->toBe(DoctorExitCode::Violation)
        ->and(exitOf([$ok], [$tool]))->toBe(DoctorExitCode::Ok)
        ->and(exitOf([$ok], [$tool], withDev: true))->toBe(DoctorExitCode::NotReady)
        ->and(exitOf([$unavailable], [$tool], withDev: true))->toBe(DoctorExitCode::Unavailable);
});

it('gives 79 when a readiness check fails and the readiness checks that require it are skipped', function (): void {
    $postgres = FakeDoctorCheck::passing(doctorId('fake.postgres'));
    $runway = FakeDoctorCheck::failing(doctorId('fake.runway'), FailureKind::Violation, false, [doctorId('fake.postgres')]);
    $archive = FakeDoctorCheck::passing(doctorId('fake.archive'), false, [doctorId('fake.runway')]);
    $report = new RunDoctor(new FakeDoctorChecks([$postgres, $runway, $archive], []))->run(new DoctorRunOptions);

    expect(array_map(static fn (CheckResult $result): string => $result->status->value, $report->results))->toBe(['pass', 'fail', 'skip'])
        ->and($report->exit)->toBe(DoctorExitCode::NotReady)
        ->and($report->exit->allowsStart())->toBeTrue();
});

it('gives 79 for a readiness check that crashes, and 78 for a blocking one', function (): void {
    $crashes = static fn (bool $blocking): DoctorCheck => new readonly class($blocking) implements DoctorCheck
    {
        public function __construct(private bool $blocking) {}

        public function id(): CheckId
        {
            return doctorId('fake.crashes');
        }

        public function blocking(): bool
        {
            return $this->blocking;
        }

        public function requires(): array
        {
            return [];
        }

        public function run(): CheckResult
        {
            throw new RuntimeException('The probe exploded.');
        }
    };

    expect(exitOf([$crashes(false)]))->toBe(DoctorExitCode::NotReady)
        ->and(exitOf([$crashes(true)]))->toBe(DoctorExitCode::Violation);
});

it('turns a check that throws or answers for another check into a violation', function (): void {
    $throws = new class implements DoctorCheck
    {
        public function id(): CheckId
        {
            return doctorId('fake.throws');
        }

        public function blocking(): bool
        {
            return true;
        }

        public function requires(): array
        {
            return [];
        }

        public function run(): CheckResult
        {
            throw new RuntimeException('The probe exploded.');
        }
    };
    $impostor = new class implements DoctorCheck
    {
        public function id(): CheckId
        {
            return doctorId('fake.impostor');
        }

        public function blocking(): bool
        {
            return false;
        }

        public function requires(): array
        {
            return [];
        }

        public function run(): CheckResult
        {
            return CheckResult::pass(doctorId('fake.other'), false, 'Fine.');
        }
    };

    $report = new RunDoctor(new FakeDoctorChecks([$throws, $impostor], []))->run(new DoctorRunOptions);

    expect($report->results[0]->status)->toBe(CheckStatus::Fail)
        ->and($report->results[0]->code)->toBe(RunDoctor::CODE_CRASHED)
        ->and($report->results[0]->cause)->toBe('It threw RuntimeException: The probe exploded.')
        ->and($report->results[1]->id->value)->toBe('fake.impostor')
        ->and($report->results[1]->blocking)->toBeFalse()
        ->and($report->results[1]->cause)->toBe('It answered for fake.other with blocking false instead of for itself.')
        ->and($report->exit)->toBe(DoctorExitCode::Violation);
});

it('turns a check that returns a skip from run() into a violation, because only the doctor skips', function (): void {
    $skips = new class implements DoctorCheck
    {
        public function id(): CheckId
        {
            return doctorId('fake.skips');
        }

        public function blocking(): bool
        {
            return true;
        }

        public function requires(): array
        {
            return [];
        }

        public function run(): CheckResult
        {
            return CheckResult::skip(doctorId('fake.skips'), true, 'Nothing to look at.', 'The check chose not to look.');
        }
    };

    $report = new RunDoctor(new FakeDoctorChecks([$skips, FakeDoctorCheck::passing(doctorId('fake.ok'))], []))->run(new DoctorRunOptions);

    expect($report->results[0]->id->value)->toBe('fake.skips')
        ->and($report->results[0]->status)->toBe(CheckStatus::Fail)
        ->and($report->results[0]->failure)->toBe(FailureKind::Violation)
        ->and($report->results[0]->code)->toBe(RunDoctor::CODE_CRASHED)
        ->and($report->results[0]->blocking)->toBeTrue()
        ->and($report->results[0]->cause)->toBe('It returned a skip from run(); only the doctor skips a check.')
        ->and($report->results[1]->status)->toBe(CheckStatus::Pass)
        ->and($report->exit)->toBe(DoctorExitCode::Violation);
});

it('asks the list of checks for the checks of the options it was given', function (): void {
    $checks = new FakeDoctorChecks([FakeDoctorCheck::passing(doctorId('fake.runtime'))], [FakeDoctorCheck::passing(doctorId('fake.dev'))]);
    $doctor = new RunDoctor($checks);

    $doctor->run(new DoctorRunOptions);
    $doctor->run(new DoctorRunOptions(dev: true));

    expect(array_map(static fn (DoctorRunOptions $options): bool => $options->dev, $checks->asked))->toBe([false, true]);
});
