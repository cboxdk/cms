<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\Dto\CredentialIssue;
use Cbox\Cms\Core\Maintenance\Domain\Dto\IssuedToken;
use Cbox\Cms\Core\Tests\Maintenance\ServiceActionWorld;
use Closure;
use DateInterval;
use PHPUnit\Framework\Assert;

/*
 * IssueServiceCredential (PRD 5.16), the action behind cms:credential:issue, called directly with
 * its DTO over ServiceActionWorld's fakes (GUARDRAILS 9): a credential of the issuer kind service
 * with the ceiling given and the actor's current generation, whose token the verifier verifies to
 * the actor; refused, with nothing issued, for an actor that is not a service actor, one that is
 * not active, and an expiry not after now.
 */

/**
 * @param  Closure(): mixed  $issue
 */
function issueRefusal(Closure $issue): CredentialIssueRefused
{
    try {
        $issue();
    } catch (CredentialIssueRefused $refused) {
        return $refused;
    }

    Assert::fail('The issue was not refused.');
}

function serviceActor(ServiceActionWorld $world, ActorState $state = ActorState::Active, int $generation = 1): Actor
{
    return $world->add(new Actor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000b1'), ActorClass::Service, $state, 2, new CredentialGeneration($generation)));
}

function credentialIssue(ServiceActionWorld $world, ActorId $actor, ClassificationAccess $ceiling = ClassificationAccess::Internal, string $lifetime = 'P90D'): CredentialIssue
{
    return new CredentialIssue($actor, $ceiling, $world->clock->now()->add(new DateInterval($lifetime)));
}

it('issues a credential of the issuer kind service with the ceiling and the actor\'s generation, whose token verifies to the actor', function (): void {
    $world = new ServiceActionWorld;
    $actor = serviceActor($world, generation: 3);

    $issued = $world->issue()->issue(credentialIssue($world, $actor->id, ClassificationAccess::Confidential));
    $principal = $world->issuer->verify($issued->token);

    expect($issued->credential->issuerKind)->toBe(IssuerKind::Service)
        ->and($issued->credential->ceiling)->toBe(ClassificationAccess::Confidential)
        ->and($issued->credential->generation->value)->toBe(3)
        ->and($issued->credential->onBehalfOf)->toBe([])
        ->and($issued->credential->expiresAt->format(DATE_ATOM))->toBe('2026-06-08T12:00:00+00:00')
        ->and($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->actor->equals($actor->id) : null)->toBeTrue()
        ->and($principal instanceof ActorPrincipal ? $principal->issuerKind : null)->toBe(IssuerKind::Service)
        ->and(count($world->issuer->issued))->toBe(1);
});

it('refuses an actor that does not exist or is a staff actor with validation_failed at actor, and issues nothing', function (): void {
    $world = new ServiceActionWorld;

    $missing = issueRefusal(static fn (): IssuedToken => $world->issue()->issue(credentialIssue($world, ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000b9'))));
    $staff = issueRefusal(static fn (): IssuedToken => $world->issue()->issue(credentialIssue($world, $world->staff)));

    expect($missing->error->code)->toBe(ErrorCode::ValidationFailed)
        ->and($missing->error->path?->toString())->toBe('actor')
        ->and($staff->error->code)->toBe(ErrorCode::ValidationFailed)
        ->and($staff->error->message)->toContain('a staff actor')
        ->and($world->issuer->issued)->toBe([]);
});

it('refuses an actor that is not active with actor_not_active, and an expiry not after now with validation_failed at expires', function (): void {
    $world = new ServiceActionWorld;
    $pending = serviceActor($world, ActorState::Pending);

    $notActive = issueRefusal(static fn (): IssuedToken => $world->issue()->issue(credentialIssue($world, $pending->id)));
    $world->add(new Actor($pending->id, ActorClass::Service, ActorState::Active, 2, CredentialGeneration::first()));
    $expired = issueRefusal(static fn (): IssuedToken => $world->issue()->issue(new CredentialIssue($pending->id, ClassificationAccess::Internal, $world->clock->now())));

    expect($notActive->error->code)->toBe(ErrorCode::ActorNotActive)
        ->and($expired->error->code)->toBe(ErrorCode::ValidationFailed)
        ->and($expired->error->path?->toString())->toBe('expires')
        ->and($world->issuer->issued)->toBe([]);
});
