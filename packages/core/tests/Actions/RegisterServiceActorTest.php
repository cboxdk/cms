<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Actions\RegisterServiceActor;
use Cbox\Cms\Core\Maintenance\Domain\Dto\RegisteredService;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ServiceRegistration;
use Cbox\Cms\Core\Maintenance\Domain\ServiceRegistrationRefused;
use Cbox\Cms\Core\Tests\Maintenance\ServiceActionWorld;
use Closure;
use PHPUnit\Framework\Assert;

/*
 * RegisterServiceActor (PRD 5.16), the action behind cms:service:create, called directly with its
 * DTO over ServiceActionWorld's fakes (GUARDRAILS 9): actor.register makes the service actor
 * pending with its responsible staff actor, then actor.activate makes it active, both as the
 * installation operator under the unit `service:<actor id>`; a responsible person that is not an
 * active staff actor and an installation without an operator stop it before anything is written;
 * an actor.activate the commit refuses leaves the actor pending and names it.
 */

const SERVICE_EMAIL = 'Importer@example.com';

function serviceRegistration(ServiceActionWorld $world, ?ActorId $responsible = null): ServiceRegistration
{
    return new ServiceRegistration(new DisplayName('Importer'), new EmailAddress(SERVICE_EMAIL), $responsible ?? $world->staff);
}

/**
 * @param  Closure(): mixed  $register
 */
function serviceRefusal(Closure $register): ServiceRegistrationRefused
{
    try {
        $register();
    } catch (ServiceRegistrationRefused $refused) {
        return $refused;
    }

    Assert::fail('The registration was not refused.');
}

it('registers the service actor pending with its responsible person, then activates it, as the operator', function (): void {
    $world = new ServiceActionWorld;

    $service = $world->registration()->register(serviceRegistration($world));
    $actor = $world->find($service->actor);
    [$register, $activate] = $world->committer->pending;

    expect($world->committed())->toBe(['actor.register', 'actor.activate'])
        ->and($register->envelope->actor->equals($world->operator))->toBeTrue()
        ->and($activate->envelope->actor->equals($world->operator))->toBeTrue()
        ->and($register->envelope->idempotencyKey->value)->toBe($activate->envelope->idempotencyKey->value)
        ->and($register->envelope->idempotencyKey->value)->toBe(Envelope::deriveKey(IssuingSurface::Maintenance, new UnitOfWork(RegisterServiceActor::UNIT_PREFIX.$service->actor->toString()))->value)
        ->and($actor?->class)->toBe(ActorClass::Service)
        ->and($actor?->state)->toBe(ActorState::Active)
        ->and($actor?->version)->toBe(2)
        ->and($service->registered->toString())->not->toBe($service->activated->toString());
});

it('refuses a responsible person that is not an active staff actor with validation_failed at responsible, before anything is written', function (): void {
    $world = new ServiceActionWorld;
    $pending = $world->add(new Actor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a3'), ActorClass::Staff, ActorState::Pending, 1, CredentialGeneration::first()));

    $refused = serviceRefusal(static fn (): RegisteredService => $world->registration()->register(serviceRegistration($world, $pending->id)));
    $service = serviceRefusal(static fn (): RegisteredService => $world->registration()->register(serviceRegistration($world, $world->operator)));

    expect($refused->error->code)->toBe(ErrorCode::ValidationFailed)
        ->and($refused->error->path?->toString())->toBe('responsible')
        ->and($refused->pending)->toBeNull()
        ->and($refused->getMessage())->not->toContain('Importer')
        ->and($service->error->code)->toBe(ErrorCode::ValidationFailed)
        ->and($world->committed())->toBe([]);
});

it('refuses before cms:install with installation_operator_missing and writes nothing', function (): void {
    $world = new ServiceActionWorld(installed: false);

    $refused = serviceRefusal(static fn (): RegisteredService => $world->registration()->register(serviceRegistration($world)));

    expect($refused->error->code)->toBe(ErrorCode::InstallationOperatorMissing)
        ->and($refused->pending)->toBeNull()
        ->and($world->committed())->toBe([]);
});

it('leaves the actor pending and names it when actor.activate does not commit', function (): void {
    $world = new ServiceActionWorld;
    $world->failingActivations = 1;

    $refused = serviceRefusal(static fn (): RegisteredService => $world->registration()->register(serviceRegistration($world)));
    $pending = $refused->pending;

    expect($refused->error->code)->toBe(ErrorCode::VersionConflict)
        ->and($pending)->toBeInstanceOf(ActorId::class)
        ->and($pending instanceof ActorId ? $world->find($pending)?->state : null)->toBe(ActorState::Pending)
        ->and($refused->getMessage())->toContain('stays pending')
        ->and($world->committed())->toBe(['actor.register']);
});
