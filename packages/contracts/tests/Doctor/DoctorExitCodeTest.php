<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;

function exitResult(string $id, ?FailureKind $failure, bool $blocking = true): CheckResult
{
    return $failure instanceof FailureKind
        ? CheckResult::fail(new CheckId($id), $blocking, $failure, 'doctor_test_failure', 'Broken.', 'Because.', 'Fix it.')
        : CheckResult::pass(new CheckId($id), $blocking, 'Fine.');
}

it('has the fixed exit codes 0, 75, 78 and 79', function (): void {
    expect(DoctorExitCode::Ok->value)->toBe(0)
        ->and(DoctorExitCode::Unavailable->value)->toBe(75)
        ->and(DoctorExitCode::Violation->value)->toBe(78)
        ->and(DoctorExitCode::NotReady->value)->toBe(79)
        ->and(DoctorExitCode::cases())->toHaveCount(4);
});

it('names each code for the JSON document', function (): void {
    expect(DoctorExitCode::Ok->status())->toBe('ok')
        ->and(DoctorExitCode::Unavailable->status())->toBe('unavailable')
        ->and(DoctorExitCode::Violation->status())->toBe('violation')
        ->and(DoctorExitCode::NotReady->status())->toBe('not_ready');
});

it('lets the kernel start at 0 and 79 only', function (): void {
    expect(DoctorExitCode::Ok->allowsStart())->toBeTrue()
        ->and(DoctorExitCode::NotReady->allowsStart())->toBeTrue()
        ->and(DoctorExitCode::Unavailable->allowsStart())->toBeFalse()
        ->and(DoctorExitCode::Violation->allowsStart())->toBeFalse();
});

it('adds up the results of a run', function (array $results, DoctorExitCode $expected): void {
    $typed = array_values(array_filter($results, static fn (mixed $result): bool => $result instanceof CheckResult));

    expect($typed)->toHaveCount(count($results))
        ->and(DoctorExitCode::for($typed))->toBe($expected);
})->with([
    'no results' => [[], DoctorExitCode::Ok],
    'passes and skips' => [[exitResult('a.a', null), CheckResult::skip(new CheckId('b.b'), true, 'Not run.', 'a.a did not pass.')], DoctorExitCode::Ok],
    'one unavailable' => [[exitResult('a.a', null), exitResult('b.b', FailureKind::Unavailable)], DoctorExitCode::Unavailable],
    'one violation' => [[exitResult('a.a', FailureKind::Violation)], DoctorExitCode::Violation],
    'a violation wins over an earlier unavailable' => [[exitResult('a.a', FailureKind::Unavailable), exitResult('b.b', FailureKind::Violation)], DoctorExitCode::Violation],
    'a violation wins over a later unavailable' => [[exitResult('a.a', FailureKind::Violation), exitResult('b.b', FailureKind::Unavailable)], DoctorExitCode::Violation],
    'a blocking unavailable and a blocking violation in any order' => [[exitResult('a.a', FailureKind::Unavailable), exitResult('b.b', FailureKind::Violation), exitResult('c.c', FailureKind::Unavailable)], DoctorExitCode::Violation],
    'a violation that does not block' => [[exitResult('a.a', FailureKind::Violation, false)], DoctorExitCode::NotReady],
    'an unavailable that does not block' => [[exitResult('a.a', null), exitResult('b.b', FailureKind::Unavailable, false)], DoctorExitCode::NotReady],
    'readiness failures of both kinds' => [[exitResult('a.a', FailureKind::Unavailable, false), exitResult('b.b', FailureKind::Violation, false), exitResult('c.c', null, false)], DoctorExitCode::NotReady],
    'a readiness failure and a skip' => [[exitResult('a.a', FailureKind::Violation, false), CheckResult::skip(new CheckId('b.b'), false, 'Not run.', 'a.a did not pass.')], DoctorExitCode::NotReady],
    'a blocking unavailable wins over an earlier readiness violation' => [[exitResult('a.a', FailureKind::Violation, false), exitResult('b.b', FailureKind::Unavailable)], DoctorExitCode::Unavailable],
    'a blocking unavailable wins over a later readiness violation' => [[exitResult('a.a', FailureKind::Unavailable), exitResult('b.b', FailureKind::Violation, false)], DoctorExitCode::Unavailable],
    'a blocking violation wins over readiness failures around it' => [[exitResult('a.a', FailureKind::Unavailable, false), exitResult('b.b', FailureKind::Violation), exitResult('c.c', FailureKind::Violation, false)], DoctorExitCode::Violation],
    'a blocking violation after a blocking unavailable and a readiness failure' => [[exitResult('a.a', FailureKind::Unavailable), exitResult('b.b', FailureKind::Unavailable, false), exitResult('c.c', FailureKind::Violation)], DoctorExitCode::Violation],
]);
