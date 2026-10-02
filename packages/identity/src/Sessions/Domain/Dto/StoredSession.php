<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\IssuedSession;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A session as the SessionStore keeps it (PRD 5.16): its key, the SHA-256 of its id; the actor who
 * logged in and the actor's class; the connection and the login method of the login; the identity
 * provider's session id, or null for a login without one, such as a local login; the actor's
 * credential generation when it was issued; and when it was issued and last seen, in UTC.
 */
#[Internal]
final readonly class StoredSession
{
    public DateTimeImmutable $issuedAt;

    public DateTimeImmutable $lastSeenAt;

    public function __construct(
        public SessionKey $key,
        public ActorId $actor,
        public ActorClass $actorClass,
        public ConnectionId $connection,
        public LoginMethod $method,
        public ?IdpSessionId $idpSession,
        public CredentialGeneration $generation,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $lastSeenAt,
    ) {
        $this->issuedAt = $issuedAt->setTimezone(new DateTimeZone('UTC'));
        $this->lastSeenAt = $lastSeenAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The session as it is after a request at $now.
     */
    public function seenAt(DateTimeImmutable $now): self
    {
        return new self($this->key, $this->actor, $this->actorClass, $this->connection, $this->method, $this->idpSession, $this->generation, $this->issuedAt, $now);
    }

    /**
     * What the contracts decide the session's principal from.
     */
    public function issued(): IssuedSession
    {
        return new IssuedSession($this->actor, $this->generation);
    }
}
