<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionRules;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use DateInterval;
use PHPUnit\Framework\Assert;

/*
 * The lifetime of a session (PRD 5.16, "Loginpolitik" and "Sessioner og credentials"), on a
 * FakeClock: a staff session ends after 60 minutes without a request, each request renews it
 * within that window, it ends 12 hours after the login whatever happens, and it is refused at its
 * next request once the login policy no longer allows how it was obtained: its connection, its
 * method, its factors, or a local login of an actor since linked to an authoritative connection. Every refusal ends the
 * session and counts it in cms.session.ended with its reason.
 */

function verifiedSession(CredentialVerifier $verifier, TransportCredential $session): ActorPrincipal
{
    $principal = $verifier->verify($session);
    Assert::assertInstanceOf(ActorPrincipal::class, $principal);

    return $principal;
}

function refusedSession(CredentialVerifier $verifier, TransportCredential $session): CredentialErrorCode
{
    try {
        $verifier->verify($session);
    } catch (CredentialRejected $rejected) {
        return $rejected->reason;
    }

    Assert::fail('The session verified.');
}

/**
 * The sessions counted as ended, by reason.
 *
 * @return array<string, int>
 */
function endedSessions(SessionWorld $world): array
{
    $ended = [];

    foreach ($world->telemetry->counters() as $counter) {
        if ($counter->name->value === SessionCounters::ENDED) {
            $reason = (string) $counter->attributes->get(SessionCounters::REASON);
            $ended[$reason] = ($ended[$reason] ?? 0) + $counter->increment;
        }
    }

    return $ended;
}

function advanceSessionClock(SessionWorld $world, string $interval): void
{
    $world->clock->advance(new DateInterval($interval));
}

it('issues a new session for a login the policy allowed, which verifies as a human on behalf of no one', function (): void {
    $world = new SessionWorld;
    $actor = $world->actor();

    $first = $world->login($actor);
    $second = $world->login($actor);
    $principal = verifiedSession($world->verifier(), SessionWorld::credential($first));

    expect($first->token->hash())->not->toBe($second->token->hash())
        ->and(SessionWorld::credential($first)->reveal())->toStartWith('cms_ss_')
        ->and($first->session->key->equals(SessionKey::of($first->token)))->toBeTrue()
        ->and($first->session->method)->toBe(LoginMethod::Password)
        ->and($first->session->connection->value)->toBe('local')
        ->and($first->session->idpSession)->toBeNull()
        ->and($first->session->generation)->toEqual($actor->credentialGeneration)
        ->and($first->session->issuedAt->format(DATE_ATOM))->toBe('2026-10-02T09:00:00+00:00')
        ->and($first->expiresAt->format(DATE_ATOM))->toBe('2026-10-02T10:00:00+00:00')
        ->and($principal->actor->equals($actor->id))->toBeTrue()
        ->and($principal->issuerKind)->toBe(IssuerKind::Human)
        ->and($principal->onBehalfOf)->toBe([])
        ->and($principal->ceiling)->toBe(ClassificationAccess::Sensitive)
        ->and($world->telemetry->counted(SessionCounters::ISSUED))->toBe(2);

    $issued = array_values(array_filter($world->telemetry->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === SessionCounters::ISSUED));
    expect($issued[0]->attributes->get(SessionCounters::METHOD))->toBe('password')
        ->and($issued[0]->attributes->names())->toBe([SessionCounters::METHOD]);
});

it('expires a session after 60 minutes without a request', function (): void {
    $world = new SessionWorld;
    $session = SessionWorld::credential($world->login($world->actor()));

    advanceSessionClock($world, 'PT59M59S');
    verifiedSession($world->verifier(), $session);

    advanceSessionClock($world, 'PT59M59S');
    verifiedSession($world->verifier(), $session);

    advanceSessionClock($world, 'PT60M');

    expect(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::Expired)
        ->and(endedSessions($world))->toBe(['expired' => 1])
        ->and(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::Unknown)
        ->and(endedSessions($world))->toBe(['expired' => 1]);
});

it('keeps an idle session in the store a while past its end, and not longer', function (): void {
    $world = new SessionWorld;
    $session = SessionWorld::credential($world->login($world->actor()));

    advanceSessionClock($world, 'PT60M');
    advanceSessionClock($world, sprintf('PT%dM', SessionRules::KEPT_AFTER_END_MINUTES));

    expect(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::Unknown)
        ->and($world->store->count())->toBe(0);
});

it('renews a session at each request within the inactivity window', function (): void {
    $world = new SessionWorld;
    $issued = $world->login($world->actor());
    $session = SessionWorld::credential($issued);

    foreach (range(1, 5) as $request) {
        advanceSessionClock($world, 'PT45M');
        verifiedSession($world->verifier(), $session);
    }

    $stored = $world->store->find($issued->session->key);

    expect($stored?->issuedAt->format(DATE_ATOM))->toBe('2026-10-02T09:00:00+00:00')
        ->and($stored?->lastSeenAt->format(DATE_ATOM))->toBe('2026-10-02T12:45:00+00:00')
        ->and($stored instanceof StoredSession ? SessionRules::expiresAt($stored, $world->policy->staff->lifetimes)->format(DATE_ATOM) : null)->toBe('2026-10-02T13:45:00+00:00');
});

it('ends a session 12 hours after the login however often it was renewed', function (): void {
    $world = new SessionWorld;
    $issued = $world->login($world->actor());
    $session = SessionWorld::credential($issued);

    foreach (range(1, 14) as $request) {
        advanceSessionClock($world, 'PT50M');
        verifiedSession($world->verifier(), $session);
    }

    // 11 hours and 40 minutes after the login, seen just now: the absolute end is nearer than the
    // inactivity end.
    $stored = $world->store->find($issued->session->key);
    expect($stored instanceof StoredSession ? SessionRules::expiresAt($stored, $world->policy->staff->lifetimes)->format(DATE_ATOM) : null)->toBe('2026-10-02T21:00:00+00:00');

    advanceSessionClock($world, 'PT19M59S');
    verifiedSession($world->verifier(), $session);

    advanceSessionClock($world, 'PT1S');

    expect(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::Expired)
        ->and(endedSessions($world))->toBe(['expired' => 1]);
});

it('applies a shortened lifetime of the policy to the sessions already issued', function (): void {
    $world = new SessionWorld;
    $session = SessionWorld::credential($world->login($world->actor()));

    advanceSessionClock($world, 'PT20M');
    $world->policy = SessionWorld::policy(['staff' => ['inactivity_minutes' => 15]]);

    expect(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::Expired);
});

it('refuses a session at its next request once the policy no longer allows its method', function (): void {
    $world = new SessionWorld;
    $passkey = SessionWorld::credential($world->login($world->actor(), LoginMethod::Passkey));
    $password = SessionWorld::credential($world->login($world->actor()));

    $world->policy = SessionWorld::policy(['staff' => ['methods' => ['password' => false]]]);

    expect(refusedSession($world->verifier(), $password))->toBe(CredentialErrorCode::NotAllowed)
        ->and(endedSessions($world))->toBe(['policy' => 1]);

    verifiedSession($world->verifier(), $passkey);

    // Allowed again, the ended session stays ended.
    $world->policy = SessionWorld::policy();

    expect(refusedSession($world->verifier(), $password))->toBe(CredentialErrorCode::Unknown);
});

it('refuses a session once the policy no longer allows its connection or local login', function (array $change): void {
    $world = new SessionWorld;
    $session = SessionWorld::credential($world->login($world->actor()));

    $world->policy = SessionWorld::policy($change);

    expect(refusedSession($world->verifier(), $session))->toBe(CredentialErrorCode::NotAllowed)
        ->and(endedSessions($world))->toBe(['policy' => 1]);
})->with([
    'the local connection is not allowed' => [['staff' => ['connections' => ['local' => false, 'entra' => true]]]],
    'local login is switched off' => [['staff' => ['local_login' => false, 'connections' => ['entra' => true]]]],
]);

it('refuses a password session at its next request once the class requires a passkey or two factors', function (): void {
    $world = new SessionWorld;
    $actor = $world->actor();
    $issued = $world->login($actor);
    $password = SessionWorld::credential($issued);
    $passkeyIssued = $world->login($actor, LoginMethod::Passkey);
    $passkey = SessionWorld::credential($passkeyIssued);

    expect($world->store->find($issued->session->key)?->factors)->toBe(LocalFactors::Password)
        ->and($world->store->find($passkeyIssued->session->key)?->factors)->toBe(LocalFactors::PasskeyOrTwoFactors);

    $world->policy = SessionWorld::policy(['staff' => ['local_factors' => 'passkey_or_two_factors']]);

    expect(refusedSession($world->verifier(), $password))->toBe(CredentialErrorCode::NotAllowed)
        ->and(endedSessions($world))->toBe(['policy' => 1]);

    verifiedSession($world->verifier(), $passkey);
});

it('refuses a local session at its next request once the actor is linked to an authoritative connection (invariant 38)', function (): void {
    $world = new SessionWorld(['authoritative_connections' => ['entra'], 'staff' => ['connections' => ['entra' => true, 'google' => true]]]);
    $linked = $world->actor();
    $other = $world->actor();
    $local = SessionWorld::credential($world->login($linked));
    $federated = SessionWorld::credential($world->login($linked, LoginMethod::Federated, 'entra', 'sid-1'));
    $kept = SessionWorld::credential($world->login($other));

    verifiedSession($world->verifier(), $local);

    // A link to a connection that is not authoritative leaves the local session alone.
    $world->links->link($other->id, new IdpIdentity(new ConnectionId('google'), new Issuer('https://accounts.google.com'), new Subject('s-2')));
    $world->links->link($linked->id, new IdpIdentity(new ConnectionId('entra'), new Issuer('https://login.example.test'), new Subject('s-1')));

    expect(refusedSession($world->verifier(), $local))->toBe(CredentialErrorCode::NotAllowed)
        ->and(endedSessions($world))->toBe(['policy' => 1]);

    verifiedSession($world->verifier(), $federated);
    verifiedSession($world->verifier(), $kept);
});

it('refuses a session of an actor that is no longer active or whose credentials were revoked, and ends it', function (): void {
    $world = new SessionWorld;
    $deactivated = $world->actor();
    $revoked = $world->actor();
    $first = SessionWorld::credential($world->login($deactivated));
    $second = SessionWorld::credential($world->login($revoked));

    $world->identity->changeState($deactivated->id, ActorState::Deactivated);
    $world->identity->revokeCredentials($revoked->id);

    expect(refusedSession($world->verifier(), $first))->toBe(CredentialErrorCode::ActorNotActive)
        ->and(refusedSession($world->verifier(), $second))->toBe(CredentialErrorCode::Revoked)
        ->and(endedSessions($world))->toBe(['revoked' => 2])
        ->and($world->store->count())->toBe(0);
});

it('ends a session at logout, every session of an actor, and every session from an IdP session', function (): void {
    $world = new SessionWorld(['staff' => ['connections' => ['entra' => true]]]);
    $actor = $world->actor();
    $other = $world->actor();
    $logout = $world->login($actor);
    $world->login($actor);
    $world->login($actor, LoginMethod::Federated, 'entra', 'sid-1');
    $federated = $world->login($other, LoginMethod::Federated, 'entra', 'sid-1');
    $kept = $world->login($other);

    expect($world->ends()->logout($logout->token))->toBeTrue()
        ->and($world->ends()->logout($logout->token))->toBeFalse()
        ->and($world->ends()->ofActor($actor->id))->toBe(2)
        ->and($world->ends()->ofIdpSession($federated->session->connection, $federated->session->idpSession ?? Assert::fail('No IdP session.')))->toBe(1)
        ->and(endedSessions($world))->toBe(['logout' => 1, 'actor' => 2, 'idp_session' => 1]);

    verifiedSession($world->verifier(), SessionWorld::credential($kept));
});

it('hands every other credential to the verifier it decorates', function (): void {
    $world = new SessionWorld;

    expect($world->verifier()->verify(null))->toEqual($world->identity->verify(null))
        ->and(static fn (): mixed => $world->verifier()->verify(new TransportCredential('not a token')))->toThrow(CredentialRejected::class)
        ->and(refusedSession($world->verifier(), TransportCredential::session('cms_ss_not-a-session')))->toBe(CredentialErrorCode::Malformed);
});

it('issues no session for a service actor, which has no login policy', function (): void {
    $world = new SessionWorld;

    expect(static fn (): mixed => $world->login($world->actor(ActorClass::Service)))->toThrow(LoginPolicyRefused::class)
        ->and($world->telemetry->counted(SessionCounters::ISSUED))->toBe(0);
});
