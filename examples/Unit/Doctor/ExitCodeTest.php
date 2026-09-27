<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;

// How cms:doctor adds up its exit code from the results of its checks, with the testkit's
// FakeDoctorCheck standing in for real checks: the blocking failures decide the code, a violation
// wins over an unavailable dependency, and a failure of a check that does not block the kernel
// from starting gives 79 only when no blocking check fails.

it('exits ok when every check passes', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::passing(new CheckId('example.second'), blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Ok)
        ->and(DoctorExitCode::Ok->value)->toBe(0)
        ->and(DoctorExitCode::Ok->status())->toBe('ok')
        ->and(DoctorExitCode::Ok->allowsStart())->toBeTrue();
});

it('exits unavailable when a blocking check cannot reach a dependency, whatever fails that does not block', function (): void {
    $results = [
        FakeDoctorCheck::failing(new CheckId('example.database'), FailureKind::Unavailable)->run(),
        FakeDoctorCheck::failing(new CheckId('example.workers'), FailureKind::Violation, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Unavailable)
        ->and(DoctorExitCode::Unavailable->value)->toBe(75)
        ->and(DoctorExitCode::Unavailable->status())->toBe('unavailable')
        ->and(DoctorExitCode::Unavailable->allowsStart())->toBeFalse();
});

it('exits violation when a blocking check is violated, whatever else is unavailable', function (): void {
    $results = [
        FakeDoctorCheck::failing(new CheckId('example.database'), FailureKind::Unavailable)->run(),
        FakeDoctorCheck::failing(new CheckId('example.setting'), FailureKind::Violation)->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Violation)
        ->and(DoctorExitCode::Violation->value)->toBe(78)
        ->and(DoctorExitCode::Violation->status())->toBe('violation')
        ->and(DoctorExitCode::Violation->allowsStart())->toBeFalse();
});

it('exits not ready when only checks that do not block fail, so the kernel may start but is not ready', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::failing(new CheckId('example.workers'), FailureKind::Violation, blocking: false)->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::NotReady)
        ->and(DoctorExitCode::NotReady->value)->toBe(79)
        ->and(DoctorExitCode::NotReady->status())->toBe('not_ready')
        ->and(DoctorExitCode::NotReady->allowsStart())->toBeTrue();
});

it('lets a test repair or break a fake check between runs, and counts the runs', function (): void {
    $check = FakeDoctorCheck::failing(new CheckId('example.setting'));

    expect($check->run()->code)->toBe(FakeDoctorCheck::CODE);

    $check->passes();

    expect($check->run()->passed())->toBeTrue()
        ->and($check->runs())->toBe(2);
});
