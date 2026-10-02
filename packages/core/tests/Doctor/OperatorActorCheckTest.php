<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Core\Doctor\Domain\Checks\OperatorActorCheck;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeOperatorProbe;

/*
 * identity.operator_actor (PRD 5.16): the installation operator exists, is of class service and is
 * active. It does not block, so a failure makes the doctor exit 79, not ready.
 */

it('passes for an active service operator and does not block', function (): void {
    $result = new OperatorActorCheck(new FakeOperatorProbe)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toBe('The installation operator '.FakeOperatorProbe::OPERATOR.' is an active service actor.');
});

it('fails with doctor_operator_missing before cms:install has run', function (): void {
    $result = new OperatorActorCheck(FakeOperatorProbe::none())->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe('doctor_operator_missing')
        ->and($result->fix)->toContain('cms:install');
});

it('fails with doctor_operator_invalid for an operator that is not an active service actor', function (ActorClass $class, ActorState $state, string $cause): void {
    $result = new OperatorActorCheck(FakeOperatorProbe::of($class, $state))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->code)->toBe('doctor_operator_invalid')
        ->and($result->cause)->toBe('The operator '.FakeOperatorProbe::OPERATOR.' is '.$cause.'.');
})->with([
    'deactivated' => [ActorClass::Service, ActorState::Deactivated, 'a service actor in the state deactivated'],
    'a staff actor' => [ActorClass::Staff, ActorState::Active, 'a staff actor in the state active'],
]);

it('fails with doctor_operator_unreadable and the probe\'s kind when the installation cannot be read', function (): void {
    $result = new OperatorActorCheck(new FakeOperatorProbe(failure: ProbeFailed::unavailable('Postgres went away.')))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Unavailable)
        ->and($result->code)->toBe('doctor_operator_unreadable')
        ->and($result->cause)->toBe('Postgres went away.');
});
