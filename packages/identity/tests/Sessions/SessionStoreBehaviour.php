<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Sessions;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use DateInterval;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SessionStore does, run against ValkeySessionStore and FakeSessionStore (GUARDRAILS
 * 9): it keeps a session under its key as it was put, records a request on it, ends one session,
 * and ends the sessions of an actor and of a (connection, IdP session id) through their sets,
 * exactly those and nothing else, so an ended session never comes back.
 */
trait SessionStoreBehaviour
{
    /** A new, empty store whose clock reads now(). */
    abstract protected function sessionStore(): SessionStore;

    /** The time of the store's clock. */
    abstract protected function now(): DateTimeImmutable;

    #[Test]
    public function it_keeps_a_session_under_its_key_as_it_was_put(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession(idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated, factors: LocalFactors::PasskeyOrTwoFactors);

        $store->put($session, $this->later('PT1H'));

        Assert::assertEquals($session, $store->find($session->key));
        Assert::assertNull($store->find($this->storedSession()->key));
    }

    #[Test]
    public function it_keeps_a_session_without_an_idp_session_id(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession();

        $store->put($session, $this->later('PT1H'));

        Assert::assertEquals($session, $store->find($session->key));
    }

    #[Test]
    public function it_records_a_request_on_a_session_and_keeps_it_longer(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession();
        $store->put($session, $this->later('PT1H'));
        $seen = $session->seenAt($this->now()->modify('+5 minutes'));

        Assert::assertTrue($store->touch($seen, $this->later('PT2H')));
        Assert::assertEquals($seen, $store->find($session->key));
        Assert::assertEquals($session->issuedAt, $store->find($session->key)?->issuedAt);
    }

    #[Test]
    public function it_ends_one_session_and_never_brings_it_back(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession();
        $other = $this->storedSession(actor: $session->actor);
        $store->put($session, $this->later('PT1H'));
        $store->put($other, $this->later('PT1H'));

        Assert::assertTrue($store->end($session->key));
        Assert::assertFalse($store->end($session->key));
        Assert::assertNull($store->find($session->key));
        Assert::assertFalse($store->touch($session->seenAt($this->now()), $this->later('PT1H')));
        Assert::assertNull($store->find($session->key));
        Assert::assertEquals($other, $store->find($other->key));
        Assert::assertSame(1, $store->endActor($session->actor));
    }

    #[Test]
    public function ending_an_actors_sessions_deletes_exactly_that_actors_sessions(): void
    {
        $store = $this->sessionStore();
        $first = $this->storedSession();
        $second = $this->storedSession(actor: $first->actor, idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated);
        $third = $this->storedSession(actor: $first->actor);
        $other = $this->storedSession(idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated);

        foreach ([$first, $second, $third, $other] as $session) {
            $store->put($session, $this->later('PT1H'));
        }

        Assert::assertSame(3, $store->endActor($first->actor));

        foreach ([$first, $second, $third] as $ended) {
            Assert::assertNull($store->find($ended->key));
        }

        Assert::assertEquals($other, $store->find($other->key));
        Assert::assertSame(0, $store->endActor($first->actor));
        // The IdP session's set no longer names the ended session.
        Assert::assertSame(1, $store->endIdpSession(new ConnectionId('entra'), new IdpSessionId('sid-1')));
        Assert::assertNull($store->find($other->key));
    }

    #[Test]
    public function ending_an_idp_session_deletes_exactly_the_sessions_from_it(): void
    {
        $store = $this->sessionStore();
        $first = $this->storedSession(idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated);
        $second = $this->storedSession(idpSession: 'sid-1', connection: 'entra', method: LoginMethod::Federated);
        $sameActor = $this->storedSession(actor: $first->actor, idpSession: 'sid-2', connection: 'entra', method: LoginMethod::Federated);
        $otherConnection = $this->storedSession(idpSession: 'sid-1', connection: 'google', method: LoginMethod::Federated);
        $local = $this->storedSession(actor: $first->actor);

        foreach ([$first, $second, $sameActor, $otherConnection, $local] as $session) {
            $store->put($session, $this->later('PT1H'));
        }

        Assert::assertSame(2, $store->endIdpSession(new ConnectionId('entra'), new IdpSessionId('sid-1')));
        Assert::assertNull($store->find($first->key));
        Assert::assertNull($store->find($second->key));

        foreach ([$sameActor, $otherConnection, $local] as $kept) {
            Assert::assertEquals($kept, $store->find($kept->key));
        }

        Assert::assertSame(0, $store->endIdpSession(new ConnectionId('entra'), new IdpSessionId('sid-1')));
        // The actor's set no longer names the ended session.
        Assert::assertSame(2, $store->endActor($first->actor));
    }

    #[Test]
    public function it_keeps_no_session_until_a_time_that_has_passed(): void
    {
        $store = $this->sessionStore();
        $session = $this->storedSession();

        foreach ([$this->now(), $this->now()->modify('-1 second')] as $end) {
            try {
                $store->put($session, $end);
                Assert::fail('A session was put with an end that has passed.');
            } catch (LogicException) {
                // Refused.
            }
        }

        Assert::assertNull($store->find($session->key));
    }

    protected function storedSession(
        ?ActorId $actor = null,
        ?string $idpSession = null,
        string $connection = 'local',
        LoginMethod $method = LoginMethod::Password,
        LocalFactors $factors = LocalFactors::Password,
    ): StoredSession {
        $now = $this->now();

        return new StoredSession(
            SessionKey::of(SessionToken::fromSecret(random_bytes(SessionToken::SECRET_BYTES))),
            $actor ?? ActorId::fromString(sprintf('01900000-0000-7000-8000-%012x', random_int(1, 0xFFFFFFFFFF))),
            ActorClass::Staff,
            new ConnectionId($connection),
            $method,
            $factors,
            $idpSession === null ? null : new IdpSessionId($idpSession),
            new CredentialGeneration(3),
            $now->modify('-10 minutes'),
            $now->modify('-1 minute'),
        );
    }

    private function later(string $interval): DateTimeImmutable
    {
        return $this->now()->add(new DateInterval($interval));
    }
}
