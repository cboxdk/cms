<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;

it('passes with its id, blocking and requirements', function (): void {
    $check = FakeDoctorCheck::passing(new CheckId('fake.roles'), false, [new CheckId('fake.postgres')]);
    $result = $check->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->id->value)->toBe('fake.roles')
        ->and($result->blocking)->toBeFalse()
        ->and($check->blocking())->toBeFalse()
        ->and(array_map(static fn (CheckId $id): string => $id->value, $check->requires()))->toBe(['fake.postgres'])
        ->and($result->explanation)->toContain('fake.roles');
});

it('fails with the kind it was given and a code, cause and fix', function (FailureKind $kind): void {
    $result = FakeDoctorCheck::failing(new CheckId('fake.postgres'), $kind)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe($kind)
        ->and($result->code)->toBe(FakeDoctorCheck::CODE)
        ->and($result->blocking)->toBeTrue()
        ->and($result->cause)->toContain('fake.postgres')
        ->and($result->fix)->toContain('passes()');
})->with([FailureKind::Violation, FailureKind::Unavailable]);

it('counts its runs and changes its outcome from the next run', function (): void {
    $check = FakeDoctorCheck::failing(new CheckId('fake.check'));

    expect($check->runs())->toBe(0)
        ->and($check->run()->failed())->toBeTrue();

    $check->passes();

    expect($check->run()->passed())->toBeTrue();

    $check->fails(FailureKind::Unavailable);

    expect($check->run()->failure)->toBe(FailureKind::Unavailable)
        ->and($check->runs())->toBe(3);
});
