<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;

function checkId(): CheckId
{
    return new CheckId('postgres.reachable');
}

it('accepts ids of two or more snake_case segments', function (string $id): void {
    expect(new CheckId($id)->value)->toBe($id);
})->with(['php.version', 'postgres.transaction_timeout', 'dev.node', 'a.b.c', 'x1.y_2']);

it('refuses other ids', function (string $id): void {
    expect(fn (): CheckId => new CheckId($id))->toThrow(InvalidDoctorCheck::class, 'is invalid');
})->with([
    'one segment' => 'postgres',
    'upper case' => 'Postgres.reachable',
    'dash' => 'postgres.max-prepared',
    'empty segment' => 'postgres..reachable',
    'leading digit' => 'php.8',
    'trailing newline' => "php.version\n",
    'too long' => 'a.'.str_repeat('b', 62),
]);

it('compares ids by value', function (): void {
    expect(checkId()->equals(new CheckId('postgres.reachable')))->toBeTrue()
        ->and(checkId()->equals(new CheckId('postgres.version')))->toBeFalse();
});

it('builds a pass with nothing but the explanation', function (): void {
    $result = CheckResult::pass(checkId(), true, 'Connected.');

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->passed())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->blocking)->toBeTrue()
        ->and([$result->failure, $result->code, $result->cause, $result->fix])->toBe([null, null, null, null]);
});

it('builds a failure with its kind, code, cause and fix', function (): void {
    $result = CheckResult::fail(checkId(), false, FailureKind::Unavailable, 'doctor_postgres_unavailable', 'Postgres cannot be reached.', 'Connection refused.', 'Start Postgres.');

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failed())->toBeTrue()
        ->and($result->blocking)->toBeFalse()
        ->and($result->failure)->toBe(FailureKind::Unavailable)
        ->and($result->code)->toBe('doctor_postgres_unavailable')
        ->and($result->cause)->toBe('Connection refused.')
        ->and($result->fix)->toBe('Start Postgres.');
});

it('builds a skip with the cause and nothing to fix', function (): void {
    $result = CheckResult::skip(checkId(), true, 'Not run.', 'php.version did not pass.');

    expect($result->status)->toBe(CheckStatus::Skip)
        ->and($result->passed())->toBeFalse()
        ->and($result->failed())->toBeFalse()
        ->and([$result->failure, $result->code, $result->fix])->toBe([null, null, null])
        ->and($result->cause)->toBe('php.version did not pass.');
});

it('refuses a result that breaks the rules of its status', function (CheckStatus $status, ?FailureKind $failure, ?string $code, ?string $cause, ?string $fix, string $message): void {
    expect(fn (): CheckResult => new CheckResult(checkId(), $status, true, 'Explained.', $failure, $code, $cause, $fix))
        ->toThrow(InvalidDoctorCheck::class, $message);
})->with([
    'a pass with a kind' => [CheckStatus::Pass, FailureKind::Violation, null, null, null, 'A pass result of check "postgres.reachable" cannot have a failure kind.'],
    'a pass with a code' => [CheckStatus::Pass, null, 'doctor_x_y', null, null, 'cannot have a code'],
    'a pass with a cause' => [CheckStatus::Pass, null, null, 'Because.', null, 'cannot have a cause'],
    'a pass with a fix' => [CheckStatus::Pass, null, null, null, 'Do it.', 'cannot have a fix'],
    'a failure without a kind' => [CheckStatus::Fail, null, 'doctor_x_y', 'Because.', 'Do it.', 'needs a failure kind'],
    'a failure without a code' => [CheckStatus::Fail, FailureKind::Violation, null, 'Because.', 'Do it.', 'needs a code'],
    'a failure without a cause' => [CheckStatus::Fail, FailureKind::Violation, 'doctor_x_y', null, 'Do it.', 'needs a cause'],
    'a failure without a fix' => [CheckStatus::Fail, FailureKind::Violation, 'doctor_x_y', 'Because.', null, 'needs a fix'],
    'a skip without a cause' => [CheckStatus::Skip, null, null, null, null, 'needs a cause'],
    'a skip with a kind' => [CheckStatus::Skip, FailureKind::Unavailable, null, 'Because.', null, 'cannot have a failure kind'],
    'a skip with a fix' => [CheckStatus::Skip, null, null, 'Because.', 'Do it.', 'cannot have a fix'],
]);

it('refuses empty texts', function (string $explanation, ?string $cause, ?string $fix, string $field): void {
    expect(fn (): CheckResult => CheckResult::fail(checkId(), true, FailureKind::Violation, 'doctor_x_y', $explanation, (string) $cause, (string) $fix))
        ->toThrow(InvalidDoctorCheck::class, sprintf('The %s of check "postgres.reachable" is empty.', $field));
})->with([
    'explanation' => [' ', 'Because.', 'Do it.', 'explanation'],
    'cause' => ['Explained.', "\n", 'Do it.', 'cause'],
    'fix' => ['Explained.', 'Because.', '', 'fix'],
]);

it('accepts error codes like the error catalog\'s and refuses others', function (string $code, bool $valid): void {
    $build = fn (): CheckResult => CheckResult::fail(checkId(), true, FailureKind::Violation, $code, 'Explained.', 'Because.', 'Do it.');

    $valid ? expect($build()->code)->toBe($code) : expect($build)->toThrow(InvalidDoctorCheck::class, 'error code');
})->with([
    ['doctor_postgres_unavailable', true],
    ['partition_missing', true],
    ['doctor_node_22', true],
    ['doctor', false],
    ['Doctor_x', false],
    ['doctor-x', false],
    ['doctor__x', false],
    ["doctor_x\n", false],
    ['doctor_'.str_repeat('x', 57), false],
]);
