<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\Uuid7;

/*
 * The actor aggregate of PRD 5.16 and 6.4: its id, class, state, version and credential
 * generation.
 */

it('has the classes and states of PRD 5.16 and 6.4', function (): void {
    expect(array_map(static fn (ActorClass $class): string => $class->value, ActorClass::cases()))->toBe(['staff', 'end_user', 'service'])
        ->and(array_map(static fn (ActorState $state): string => $state->value, ActorState::cases()))->toBe(['pending', 'active', 'deactivated', 'deprovisioned']);
});

it('counts only an active actor as active, and revokes credentials on deactivation and deprovisioning', function (ActorState $state, bool $active, bool $revokes): void {
    expect($state->isActive())->toBe($active)
        ->and($state->revokesCredentials())->toBe($revokes);
})->with([
    [ActorState::Pending, false, false],
    [ActorState::Active, true, false],
    [ActorState::Deactivated, false, true],
    [ActorState::Deprovisioned, false, true],
]);

it('starts the version and the generation at 1 and refuses less', function (): void {
    $id = ActorId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');

    expect(new Actor($id, ActorClass::Staff, ActorState::Active, 1, CredentialGeneration::first())->isActive())->toBeTrue()
        ->and(fn (): Actor => new Actor($id, ActorClass::Staff, ActorState::Active, 0, CredentialGeneration::first()))
        ->toThrow(InvalidIdentity::class, 'An actor\'s version starts at 1, got 0.')
        ->and(fn (): CredentialGeneration => new CredentialGeneration(0))
        ->toThrow(InvalidIdentity::class, 'A credential generation starts at 1, got 0.');
});

it('counts the generation up and compares it', function (): void {
    $first = CredentialGeneration::first();
    $second = $first->next();

    expect($second->value)->toBe(2)
        ->and($first->isBelow($second))->toBeTrue()
        ->and($second->isBelow($first))->toBeFalse()
        ->and($first->isBelow(CredentialGeneration::first()))->toBeFalse()
        ->and($second->equals(new CredentialGeneration(2)))->toBeTrue()
        ->and($second->equals($first))->toBeFalse();
});

it('keeps an actor id as its UUIDv7 and refuses anything else', function (): void {
    $uuid = new Uuid7('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    $id = ActorId::fromString($uuid->value);

    expect($id->toString())->toBe($uuid->value)
        ->and($id->equals(new ActorId($uuid)))->toBeTrue()
        ->and($id->equals(ActorId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c')))->toBeFalse()
        ->and(fn (): ActorId => ActorId::fromString('user:7'))->toThrow(InvalidUuid7::class);
});
