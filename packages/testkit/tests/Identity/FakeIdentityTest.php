<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Identity;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;

/*
 * What the fake and its seeder do beyond the shared suites (PRD 2.31, 5.16): a malformed token is
 * refused without a lookup, and the seeder refuses a credential it may not issue.
 */

function fakeIdentityExpiry(FakeClock $clock): DateTimeImmutable
{
    return $clock->now()->add(new DateInterval('P1D'));
}

it('refuses a wrong checksum without a lookup, and looks up a well-formed token', function (): void {
    $clock = new FakeClock;
    $identity = new FakeIdentity($clock);
    $actor = $identity->addActor(ActorClass::Service);
    $token = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, fakeIdentityExpiry($clock)))->reveal();

    expect(fn (): Principal => $identity->verify(new TransportCredential(substr($token, 0, -8).'ffffffff')))->toThrow(CredentialRejected::class)
        ->and($identity->lookups())->toBe(0);

    $identity->verify(new TransportCredential($token));
    $identity->verify(null);

    expect($identity->lookups())->toBe(1);
});

it('refuses an agent credential with a ceiling above confidential at creation', function (ClassificationAccess $ceiling): void {
    $clock = new FakeClock;
    $actor = new FakeIdentity($clock)->addActor(ActorClass::Service);

    expect(fn (): ServiceCredentialSpec => new ServiceCredentialSpec($actor->id, IssuerKind::Agent, $ceiling, fakeIdentityExpiry($clock)))
        ->toThrow(InvalidIdentity::class, 'at most confidential, got '.$ceiling->value);
})->with([ClassificationAccess::Personal, ClassificationAccess::Sensitive]);

it('issues service credentials only to active service actors, on behalf of active actors, with an expiry ahead', function (): void {
    $clock = new FakeClock;
    $identity = new FakeIdentity($clock);
    $staff = $identity->addActor(ActorClass::Staff);
    $service = $identity->addActor(ActorClass::Service);
    $pending = $identity->addActor(ActorClass::Service, ActorState::Pending);
    $gone = $identity->addActor(ActorClass::Staff, ActorState::Deactivated);
    $unknown = new ActorId(new FakeIdGenerator(seed: 42)->next());

    expect(fn (): TransportCredential => $identity->issue(new ServiceCredentialSpec($staff->id, IssuerKind::Service, ClassificationAccess::Public, fakeIdentityExpiry($clock))))
        ->toThrow(InvalidIdentity::class, 'only for an actor of the class service')
        ->and(fn (): TransportCredential => $identity->issue(new ServiceCredentialSpec($pending->id, IssuerKind::Service, ClassificationAccess::Public, fakeIdentityExpiry($clock))))
        ->toThrow(InvalidIdentity::class, 'is pending')
        ->and(fn (): TransportCredential => $identity->issue(new ServiceCredentialSpec($service->id, IssuerKind::Agent, ClassificationAccess::Public, fakeIdentityExpiry($clock), [$gone->id])))
        ->toThrow(InvalidIdentity::class, 'is deactivated')
        ->and(fn (): TransportCredential => $identity->issue(new ServiceCredentialSpec($service->id, IssuerKind::Service, ClassificationAccess::Public, $clock->now())))
        ->toThrow(InvalidIdentity::class, 'with an expiry after the time it is issued at')
        ->and(fn (): TransportCredential => $identity->issue(new ServiceCredentialSpec($unknown, IssuerKind::Service, ClassificationAccess::Public, fakeIdentityExpiry($clock))))
        ->toThrow(InvalidIdentity::class, 'No actor has the id '.$unknown->toString())
        ->and(fn (): Actor => $identity->revokeCredentials($unknown))
        ->toThrow(InvalidIdentity::class, 'No actor has the id');
});

it('stores a credential only as the hash of its token', function (): void {
    $clock = new FakeClock;
    $identity = new FakeIdentity($clock);
    $actor = $identity->addActor(ActorClass::Service);
    $token = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, fakeIdentityExpiry($clock)))->reveal();

    expect(serialize($identity))->not->toContain($token)
        ->and(serialize($identity))->toContain(hash('sha256', $token));
});

it('gives a well-formed token of another installation credential_unknown', function (): void {
    $identity = new FakeIdentity;
    $other = new FakeIdentity;
    $actor = $other->addActor(ActorClass::Service);
    $token = $other->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, new DateTimeImmutable('2100-01-01T00:00:00Z')));

    try {
        $identity->verify($token);
        throw new AssertionFailedError('The token was verified.');
    } catch (CredentialRejected $rejected) {
        expect($rejected->reason)->toBe(CredentialErrorCode::Unknown);
    }
});
