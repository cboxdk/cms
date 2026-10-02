<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Sessions\Fakes;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * The session store in memory, held to ValkeySessionStore by SessionStoreBehaviour. It keeps each
 * session until the end it was put or last touched with, read from its Clock, as Valkey keeps a
 * key until its TTL, and keeps the sets of the actors and the IdP sessions as Valkey does, so
 * ending one takes a session out of the other.
 */
final class FakeSessionStore implements SessionStore
{
    /** @var array<string, array{StoredSession, DateTimeImmutable}> by session key */
    private array $sessions = [];

    /** @var array<string, array<string, true>> session keys by the name of their set */
    private array $sets = [];

    public function __construct(private readonly Clock $clock) {}

    #[Override]
    public function put(StoredSession $session, DateTimeImmutable $expiresAt): void
    {
        $this->future($expiresAt);
        $this->end($session->key);
        $this->sessions[$session->key->value] = [$session, $expiresAt];

        foreach ($this->setsOf($session) as $set) {
            $this->sets[$set][$session->key->value] = true;
        }
    }

    #[Override]
    public function find(SessionKey $key): ?StoredSession
    {
        return $this->live($key)[0] ?? null;
    }

    #[Override]
    public function touch(StoredSession $session, DateTimeImmutable $expiresAt): bool
    {
        $this->future($expiresAt);
        $stored = $this->live($session->key);

        if ($stored === null) {
            return false;
        }

        $this->sessions[$session->key->value] = [$stored[0]->seenAt($session->lastSeenAt), $expiresAt];

        return true;
    }

    #[Override]
    public function end(SessionKey $key): bool
    {
        $stored = $this->live($key);
        $this->drop($key->value);

        return $stored !== null;
    }

    #[Override]
    public function endActor(ActorId $actor): int
    {
        return $this->endSet('actor:'.$actor->toString());
    }

    #[Override]
    public function endIdpSession(ConnectionId $connection, IdpSessionId $idpSession): int
    {
        return $this->endSet($this->idpSet($connection, $idpSession));
    }

    /**
     * How many sessions the store holds, expired ones included until they are dropped.
     */
    public function count(): int
    {
        return count($this->sessions);
    }

    private function endSet(string $set): int
    {
        $ended = 0;

        foreach (array_keys($this->sets[$set] ?? []) as $key) {
            $ended += $this->end(new SessionKey($key)) ? 1 : 0;
        }

        unset($this->sets[$set]);

        return $ended;
    }

    /**
     * @return array{StoredSession, DateTimeImmutable}|null
     */
    private function live(SessionKey $key): ?array
    {
        $stored = $this->sessions[$key->value] ?? null;

        if ($stored !== null && $stored[1] <= $this->clock->now()) {
            $this->drop($key->value);

            return null;
        }

        return $stored;
    }

    private function drop(string $key): void
    {
        $stored = $this->sessions[$key] ?? null;
        unset($this->sessions[$key]);

        if ($stored === null) {
            return;
        }

        foreach ($this->setsOf($stored[0]) as $set) {
            unset($this->sets[$set][$key]);
        }
    }

    /**
     * @return list<string>
     */
    private function setsOf(StoredSession $session): array
    {
        $sets = ['actor:'.$session->actor->toString()];

        if ($session->idpSession instanceof IdpSessionId) {
            $sets[] = $this->idpSet($session->connection, $session->idpSession);
        }

        return $sets;
    }

    private function idpSet(ConnectionId $connection, IdpSessionId $idpSession): string
    {
        return 'idp:'.$connection->value.':'.$idpSession->value;
    }

    private function future(DateTimeImmutable $expiresAt): void
    {
        if ($expiresAt <= $this->clock->now()) {
            throw new LogicException('A session is kept until a time after now; its end has passed.');
        }
    }
}
