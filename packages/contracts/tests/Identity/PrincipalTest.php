<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Principals and issued credentials (PRD 2.31, 5.16, 12.2, invariant 25): an agent's
 * classification ceiling never exceeds confidential, the anonymous principal reads only public, an
 * on-behalf-of chain holds no actor twice, and IssuedCredential::principal() refuses in the order
 * CredentialVerifier gives.
 */

/**
 * A new actor id on each call.
 */
function identityActorId(): ActorId
{
    static $next = 0;
    $next = is_int($next) ? $next + 1 : 1;

    return ActorId::fromString(sprintf('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04x', $next));
}

function identityActor(ActorId $id, ActorState $state = ActorState::Active, int $generation = 1, ActorClass $class = ActorClass::Service): Actor
{
    return new Actor($id, $class, $state, 1, new CredentialGeneration($generation));
}

/**
 * @param  list<ActorId>  $chain
 */
function issuedCredential(ActorId $actor, array $chain = [], int $generation = 1, string $expiresAt = '2031-05-01T10:00:00Z'): IssuedCredential
{
    return new IssuedCredential($actor, $chain, IssuerKind::Service, ClassificationAccess::Internal, new CredentialGeneration($generation), new DateTimeImmutable($expiresAt));
}

it('gives the anonymous principal public classification access', function (): void {
    expect(new AnonymousPrincipal()->classificationCeiling())->toBe(ClassificationAccess::Public);
});

it('refuses an agent credential with a ceiling above confidential at creation', function (ClassificationAccess $ceiling): void {
    $actor = identityActorId();
    $expiresAt = new DateTimeImmutable('2031-05-01T10:00:00Z');

    expect(fn (): IssuedCredential => new IssuedCredential($actor, [], IssuerKind::Agent, $ceiling, CredentialGeneration::first(), $expiresAt))
        ->toThrow(InvalidIdentity::class, sprintf('A credential of the issuer kind agent has a classification ceiling of at most confidential, got %s (PRD 2.31, 12.2).', $ceiling->value))
        ->and(fn (): ActorPrincipal => new ActorPrincipal($actor, [], IssuerKind::Agent, $ceiling))
        ->toThrow(InvalidIdentity::class, 'at most confidential');
})->with([ClassificationAccess::Personal, ClassificationAccess::Sensitive]);

it('accepts an agent credential up to confidential, and a service credential up to sensitive', function (IssuerKind $kind, ClassificationAccess $ceiling): void {
    $principal = new ActorPrincipal(identityActorId(), [], $kind, $ceiling);

    expect($principal->classificationCeiling())->toBe($ceiling)
        ->and($kind->permits($ceiling))->toBeTrue();
})->with([
    [IssuerKind::Agent, ClassificationAccess::Public],
    [IssuerKind::Agent, ClassificationAccess::Internal],
    [IssuerKind::Agent, ClassificationAccess::Confidential],
    [IssuerKind::Service, ClassificationAccess::Personal],
    [IssuerKind::Service, ClassificationAccess::Sensitive],
]);

it('gives each issuer kind its highest ceiling', function (): void {
    expect(IssuerKind::Agent->maximumCeiling())->toBe(ClassificationAccess::Confidential)
        ->and(IssuerKind::Service->maximumCeiling())->toBe(ClassificationAccess::Sensitive);
});

it('refuses a chain that holds the actor itself or an actor twice', function (): void {
    $actor = identityActorId();
    $other = identityActorId();

    expect(fn (): ActorPrincipal => new ActorPrincipal($actor, [$actor], IssuerKind::Service, ClassificationAccess::Public))
        ->toThrow(InvalidIdentity::class, 'holds '.$actor->toString().' again')
        ->and(fn (): ActorPrincipal => new ActorPrincipal($actor, [$other, $other], IssuerKind::Service, ClassificationAccess::Public))
        ->toThrow(InvalidIdentity::class, 'holds '.$other->toString().' again')
        ->and(fn (): IssuedCredential => issuedCredential($actor, [$other, $actor]))
        ->toThrow(InvalidIdentity::class, 'holds '.$actor->toString().' again');
});

it('orders the classification classes from public to sensitive', function (): void {
    $ranks = array_map(static fn (ClassificationAccess $access): int => $access->rank(), ClassificationAccess::cases());

    expect($ranks)->toBe([0, 1, 2, 3, 4])
        ->and(ClassificationAccess::Confidential->allows(ClassificationAccess::Internal))->toBeTrue()
        ->and(ClassificationAccess::Confidential->allows(ClassificationAccess::Confidential))->toBeTrue()
        ->and(ClassificationAccess::Confidential->allows(ClassificationAccess::Personal))->toBeFalse()
        ->and(ClassificationAccess::Public->allows(ClassificationAccess::Internal))->toBeFalse()
        ->and(ClassificationAccess::Sensitive->atMost(ClassificationAccess::Internal))->toBe(ClassificationAccess::Internal)
        ->and(ClassificationAccess::Public->atMost(ClassificationAccess::Personal))->toBe(ClassificationAccess::Public);
});

it('gives the principal of a valid credential with its chain in order', function (): void {
    $actor = identityActorId();
    $first = identityActorId();
    $second = identityActorId();

    $principal = issuedCredential($actor, [$first, $second])->principal(
        identityActor($actor),
        [identityActor($first, class: ActorClass::Staff), identityActor($second, class: ActorClass::Staff)],
        new DateTimeImmutable('2031-05-01T09:59:59.999999Z'),
    );

    expect($principal->actor)->toBe($actor)
        ->and($principal->onBehalfOf)->toBe([$first, $second])
        ->and($principal->issuerKind)->toBe(IssuerKind::Service)
        ->and($principal->ceiling)->toBe(ClassificationAccess::Internal);
});

it('refuses an expired credential, an inactive actor or chain and a stale generation, in that order', function (callable $case, CredentialErrorCode $reason): void {
    $actor = identityActorId();
    $person = identityActorId();

    try {
        $case($actor, $person);
    } catch (CredentialRejected $rejected) {
        expect($rejected->reason)->toBe($reason)
            ->and($rejected->getMessage())->toStartWith('The credential was refused: ');

        return;
    }

    throw new AssertionFailedError('The credential was not refused.');
})->with([
    'at its expiry' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor)->principal(identityActor($actor), [], new DateTimeImmutable('2031-05-01T10:00:00Z')),
        CredentialErrorCode::Expired,
    ],
    'expired and deactivated' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor)->principal(identityActor($actor, ActorState::Deactivated, 2), [], new DateTimeImmutable('2031-06-01T00:00:00Z')),
        CredentialErrorCode::Expired,
    ],
    'actor deactivated' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor)->principal(identityActor($actor, ActorState::Deactivated, 2), [], new DateTimeImmutable('2031-05-01T09:00:00Z')),
        CredentialErrorCode::ActorNotActive,
    ],
    'actor pending' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor)->principal(identityActor($actor, ActorState::Pending), [], new DateTimeImmutable('2031-05-01T09:00:00Z')),
        CredentialErrorCode::ActorNotActive,
    ],
    'chain deprovisioned' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor, [$person])->principal(identityActor($actor), [identityActor($person, ActorState::Deprovisioned, 2)], new DateTimeImmutable('2031-05-01T09:00:00Z')),
        CredentialErrorCode::ActorNotActive,
    ],
    'generation below the actor\'s' => [
        fn (ActorId $actor, ActorId $person): ActorPrincipal => issuedCredential($actor)->principal(identityActor($actor, generation: 2), [], new DateTimeImmutable('2031-05-01T09:00:00Z')),
        CredentialErrorCode::Revoked,
    ],
]);

it('accepts a credential whose generation is the actor\'s', function (): void {
    $actor = identityActorId();

    expect(issuedCredential($actor, generation: 3)->principal(identityActor($actor, generation: 3), [], new DateTimeImmutable('2031-05-01T09:00:00Z')))
        ->toBeInstanceOf(ActorPrincipal::class);
});

it('refuses to decide with actors that are not the credential\'s', function (): void {
    $actor = identityActorId();
    $other = identityActorId();
    $now = new DateTimeImmutable('2031-05-01T09:00:00Z');

    expect(fn (): ActorPrincipal => issuedCredential($actor)->principal(identityActor($other), [], $now))
        ->toThrow(InvalidIdentity::class, 'do not match its actor')
        ->and(fn (): ActorPrincipal => issuedCredential($actor, [$other])->principal(identityActor($actor), [], $now))
        ->toThrow(InvalidIdentity::class, 'do not match its on-behalf-of chain')
        ->and(fn (): ActorPrincipal => issuedCredential($actor, [$other])->principal(identityActor($actor), [identityActor($actor)], $now))
        ->toThrow(InvalidIdentity::class, 'do not match its on-behalf-of chain');
});
