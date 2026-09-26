<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Core\Doctor\Actions\RunDoctor;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use RuntimeException;

/*
 * The doctor runs the checks in order, skips a check whose requirement did not pass, turns a
 * check that breaks its contract into a failure, and adds up the exit code.
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
    return array_map(static fn (CheckResult $result): string => $result->id->value.' '.$result->status->value, $doctor->run($dev)->results);
}

it('runs the runtime checks, and the dev checks after them with --dev', function (): void {
    $runtime = FakeDoctorCheck::passing(doctorId('fake.runtime'));
    $dev = FakeDoctorCheck::passing(doctorId('fake.dev'), false, [doctorId('fake.runtime')]);
    $doctor = new RunDoctor(new DoctorChecks([$runtime], [$dev]));

    expect(statuses($doctor))->toBe(['fake.runtime pass'])
        ->and($dev->runs())->toBe(0)
        ->and(statuses($doctor, true))->toBe(['fake.runtime pass', 'fake.dev pass'])
        ->and($doctor->run(true)->dev)->toBeTrue()
        ->and($doctor->run(false)->dev)->toBeFalse();
});

it('skips a check whose requirement did not pass, and names the requirement', function (): void {
    $postgres = FakeDoctorCheck::failing(doctorId('fake.postgres'), FailureKind::Unavailable);
    $roles = FakeDoctorCheck::passing(doctorId('fake.roles'), true, [doctorId('fake.postgres')]);
    $grants = FakeDoctorCheck::passing(doctorId('fake.grants'), false, [doctorId('fake.roles')]);
    $valkey = FakeDoctorCheck::passing(doctorId('fake.valkey'));
    $report = new RunDoctor(new DoctorChecks([$postgres, $roles, $grants, $valkey], []))->run(false);

    expect(array_map(static fn (CheckResult $result): string => $result->status->value, $report->results))->toBe(['fail', 'skip', 'skip', 'pass'])
        ->and($roles->runs())->toBe(0)
        ->and($grants->runs())->toBe(0)
        ->and($report->results[1]->cause)->toBe('fake.postgres did not pass.')
        ->and($report->results[1]->explanation)->toContain('needs fake.postgres to pass first')
        ->and($report->results[2]->cause)->toBe('fake.roles did not pass.')
        ->and($report->results[2]->blocking)->toBeFalse()
        ->and($report->exit)->toBe(DoctorExitCode::Unavailable);

    $postgres->passes();

    expect(statuses(new RunDoctor(new DoctorChecks([$postgres, $roles, $grants, $valkey], []))))
        ->toBe(['fake.postgres pass', 'fake.roles pass', 'fake.grants pass', 'fake.valkey pass']);
});

it('adds up the exit code: a violation wins, and a failure that does not block still counts', function (): void {
    $unavailable = FakeDoctorCheck::failing(doctorId('fake.valkey'), FailureKind::Unavailable);
    $readiness = FakeDoctorCheck::failing(doctorId('fake.runway'), FailureKind::Violation, false);

    expect(new RunDoctor(new DoctorChecks([FakeDoctorCheck::passing(doctorId('fake.ok'))], []))->run(false)->exit)->toBe(DoctorExitCode::Ok)
        ->and(new RunDoctor(new DoctorChecks([$unavailable], []))->run(false)->exit)->toBe(DoctorExitCode::Unavailable)
        ->and(new RunDoctor(new DoctorChecks([$unavailable, $readiness], []))->run(false)->exit)->toBe(DoctorExitCode::Violation)
        ->and(new RunDoctor(new DoctorChecks([$readiness], []))->run(false)->exit)->toBe(DoctorExitCode::Violation)
        ->and(new RunDoctor(new DoctorChecks([], [$unavailable]))->run(false)->exit)->toBe(DoctorExitCode::Ok)
        ->and(new RunDoctor(new DoctorChecks([], [$unavailable]))->run(true)->exit)->toBe(DoctorExitCode::Unavailable);
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

    $report = new RunDoctor(new DoctorChecks([$throws, $impostor], []))->run(false);

    expect($report->results[0]->status)->toBe(CheckStatus::Fail)
        ->and($report->results[0]->code)->toBe(RunDoctor::CODE_CRASHED)
        ->and($report->results[0]->cause)->toBe('It threw RuntimeException: The probe exploded.')
        ->and($report->results[1]->id->value)->toBe('fake.impostor')
        ->and($report->results[1]->blocking)->toBeFalse()
        ->and($report->results[1]->cause)->toContain('answered for fake.other')
        ->and($report->exit)->toBe(DoctorExitCode::Violation);
});

it('refuses a list with a duplicate id or a requirement that does not run earlier', function (): void {
    $a = FakeDoctorCheck::passing(doctorId('fake.a'));

    expect(fn (): DoctorChecks => new DoctorChecks([$a], [FakeDoctorCheck::passing(doctorId('fake.a'))]))
        ->toThrow(InvalidDoctorCheck::class, 'The check "fake.a" is listed twice.')
        ->and(fn (): DoctorChecks => new DoctorChecks([FakeDoctorCheck::passing(doctorId('fake.b'), true, [doctorId('fake.a')]), $a], []))
        ->toThrow(InvalidDoctorCheck::class, 'The check "fake.b" requires "fake.a", which is not listed before it.')
        ->and(fn (): DoctorChecks => new DoctorChecks([], [FakeDoctorCheck::passing(doctorId('fake.b'), true, [doctorId('fake.missing')])]))
        ->toThrow(InvalidDoctorCheck::class, 'requires "fake.missing"')
        ->and(new DoctorChecks([$a], [FakeDoctorCheck::passing(doctorId('fake.b'), true, [doctorId('fake.a')])])->for(true))->toHaveCount(2);
});
