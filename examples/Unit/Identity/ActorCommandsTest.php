<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivatedV1;

// An administrator's command, or the scheduler's inactivity rule, deactivates an actor with the
// kernel's actor.deactivate. The event tells a subscriber the actor, the source, the credential
// generation below which every credential is refused, and how many direct grants ended.

it('deactivates an actor and notes what deactivated it', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $byAdministrator = new DeactivateActor($actor);
    $byRule = new DeactivateActor($actor, DeactivationSource::Inactivity);

    expect($byAdministrator->source)->toBe(DeactivationSource::Local)
        ->and($byRule->source->value)->toBe('inactivity');
});

it('tells about the deactivation with ids, the source and counts', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $event = new ActorDeactivated(2, new ActorDeactivatedV1($actor, DeactivationSource::Local, credentialGeneration: 2, grantsEnded: 3));
    $data = $event->payload()->data();

    expect(ActorDeactivated::type()->name)->toBe('actor.deactivated')
        ->and($event->aggregate()->id->toString())->toBe($actor->toString())
        ->and($event->aggregate()->version)->toBe(2)
        ->and($data->get('source')->kind)->toBe(DatumKind::Enum)
        ->and($data->get('source')->asEnumValue())->toBe('local')
        ->and($data->get('grants_ended')->asInteger())->toBe(3);
});
