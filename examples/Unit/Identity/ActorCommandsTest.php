<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivatedV1;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegistered;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegisteredV1;

// A registration is actor.register, which creates the actor pending with its profile, and then,
// once its credential is written, actor.activate. The profile is personal data: the event of the
// registration carries ids and the class, never the name or the email. An administrator's
// command, or the scheduler's inactivity rule, deactivates an actor with actor.deactivate. Its
// event tells a subscriber the actor, the source, the credential generation below which every
// credential is refused, and how many direct grants ended.

it('registers a service actor with the staff actor responsible for it, and activates it at the version read', function (): void {
    $service = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000202');
    $responsible = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $register = new RegisterActor($service, ActorClass::Service, new DisplayName('Nightly import'), new EmailAddress('ops@example.com'), $responsible);
    $activate = new ActivateActor($service, new AggregateVersion(1));

    expect($register->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($activate->expectedVersions()->reads[0]->version?->value)->toBe(1);
});

it('refuses a display name or an email that breaks its form, without repeating it', function (): void {
    expect(static fn (): DisplayName => new DisplayName(' Mette'))->toThrow(InvalidIdentity::class, 'A display name is 1 to 200 characters')
        ->and(static fn (): EmailAddress => new EmailAddress('mette at example'))->toThrow(InvalidIdentity::class, 'An email address is a local part');
});

it('tells about the registration with ids and the class, never the profile', function (): void {
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000203');

    $event = new ActorRegistered(1, new ActorRegisteredV1($actor, ActorClass::Staff, null, credentialGeneration: 1));
    $data = $event->payload()->data();

    expect(ActorRegistered::type()->name)->toBe('actor.registered')
        ->and($data->get('class')->asEnumValue())->toBe('staff')
        ->and($data->get('responsible')->kind)->toBe(DatumKind::Null);
});

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
