<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use DateTimeImmutable;
use LogicException;

/**
 * Where sessions live (PRD 5.16): under the SHA-256 of their id, with a set of the sessions of
 * each actor and of each (connection, IdP session id), so ending them deletes what the set names
 * and never scans.
 *
 * A session is kept at least until the expiry it was put or last touched with, and may be dropped
 * from then on; the verifier decides expiry itself from the times the session holds. Ending
 * removes a session from every set it is in.
 */
#[Internal]
interface SessionStore
{
    /**
     * Stores a new session, kept at least until $expiresAt, and adds it to the set of its actor
     * and, when it has one, to the set of its connection and IdP session id.
     *
     * @throws LogicException when $expiresAt is not after the Clock's time
     */
    public function put(StoredSession $session, DateTimeImmutable $expiresAt): void;

    /**
     * The session stored under the key, or null when there is none.
     */
    public function find(SessionKey $key): ?StoredSession;

    /**
     * Records that the session was seen at its lastSeenAt and keeps it at least until $expiresAt.
     * Returns false and stores nothing when the session is no longer there, such as when it was
     * ended meanwhile, so an ended session never comes back.
     *
     * @throws LogicException when $expiresAt is not after the Clock's time
     */
    public function touch(StoredSession $session, DateTimeImmutable $expiresAt): bool;

    /**
     * Ends one session. Returns whether it was there.
     */
    public function end(SessionKey $key): bool;

    /**
     * Ends every session of the actor, as its set names them, and returns how many there were.
     */
    public function endActor(ActorId $actor): int;

    /**
     * Ends every session that came from the IdP session, as the set of the connection and the IdP
     * session id names them, and returns how many there were.
     */
    public function endIdpSession(ConnectionId $connection, IdpSessionId $idpSession): int;
}
