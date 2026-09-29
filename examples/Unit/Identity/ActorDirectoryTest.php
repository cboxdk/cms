<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

// The actor directory on the testkit's fake. The seeder side of FakeIdentity creates and changes
// actors, as the identity commands will; the directory side reads them back as aggregates.

it('reads an actor with its class, state, version and credential generation', function (): void {
    $identity = new FakeIdentity;
    $editor = $identity->addActor(ActorClass::Staff);
    $directory = $identity->directory();

    $actor = $directory->find($editor->id);

    expect($directory)->toBeInstanceOf(ActorDirectory::class)
        ->and($actor?->class)->toBe(ActorClass::Staff)
        ->and($actor?->state)->toBe(ActorState::Active)
        ->and($actor?->version)->toBe(1)
        ->and($actor?->credentialGeneration->value)->toBe(1);
});

it('counts the version and the credential generation up when an actor is deactivated', function (): void {
    $identity = new FakeIdentity;
    $editor = $identity->addActor(ActorClass::Staff);

    $identity->changeState($editor->id, ActorState::Deactivated);
    $actor = $identity->directory()->find($editor->id);

    expect($actor?->isActive())->toBeFalse()
        ->and($actor?->version)->toBe(2)
        ->and($actor?->credentialGeneration->value)->toBe(2);
});
