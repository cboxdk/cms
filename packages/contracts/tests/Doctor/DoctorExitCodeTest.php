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

it('has the fixed exit codes 0, 75 and 78', function (): void {
    expect(DoctorExitCode::Ok->value)->toBe(0)
        ->and(DoctorExitCode::Unavailable->value)->toBe(75)
        ->and(DoctorExitCode::Violation->value)->toBe(78)
        ->and(DoctorExitCode::cases())->toHaveCount(3);
});

it('names each code for the JSON document', function (): void {
    expect(DoctorExitCode::Ok->status())->toBe('ok')
        ->and(DoctorExitCode::Unavailable->status())->toBe('unavailable')
        ->and(DoctorExitCode::Violation->status())->toBe('violation');
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
    'a failure that does not block still counts' => [[exitResult('a.a', FailureKind::Violation, false)], DoctorExitCode::Violation],
]);
