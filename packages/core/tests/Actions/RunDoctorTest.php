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

it('adds up the exit code: a violation wins, and a failure that does not block still counts', function (): void {
    $unavailable = FakeDoctorCheck::failing(doctorId('fake.valkey'), FailureKind::Unavailable);
    $readiness = FakeDoctorCheck::failing(doctorId('fake.runway'), FailureKind::Violation, false);

    expect(new RunDoctor(new FakeDoctorChecks([FakeDoctorCheck::passing(doctorId('fake.ok'))], []))->run(new DoctorRunOptions)->exit)->toBe(DoctorExitCode::Ok)
        ->and(new RunDoctor(new FakeDoctorChecks([$unavailable], []))->run(new DoctorRunOptions)->exit)->toBe(DoctorExitCode::Unavailable)
        ->and(new RunDoctor(new FakeDoctorChecks([$unavailable, $readiness], []))->run(new DoctorRunOptions)->exit)->toBe(DoctorExitCode::Violation)
        ->and(new RunDoctor(new FakeDoctorChecks([$readiness], []))->run(new DoctorRunOptions)->exit)->toBe(DoctorExitCode::Violation)
        ->and(new RunDoctor(new FakeDoctorChecks([], [$unavailable]))->run(new DoctorRunOptions)->exit)->toBe(DoctorExitCode::Ok)
        ->and(new RunDoctor(new FakeDoctorChecks([], [$unavailable]))->run(new DoctorRunOptions(dev: true))->exit)->toBe(DoctorExitCode::Unavailable);
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

it('asks the list of checks for the checks of the options it was given', function (): void {
    $checks = new FakeDoctorChecks([FakeDoctorCheck::passing(doctorId('fake.runtime'))], [FakeDoctorCheck::passing(doctorId('fake.dev'))]);
    $doctor = new RunDoctor($checks);

    $doctor->run(new DoctorRunOptions);
    $doctor->run(new DoctorRunOptions(dev: true));

    expect(array_map(static fn (DoctorRunOptions $options): bool => $options->dev, $checks->asked))->toBe([false, true]);
});
