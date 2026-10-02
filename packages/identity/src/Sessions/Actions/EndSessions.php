<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionEndReason;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;

/**
 * Ends sessions (PRD 5.16): one at a logout, every session of an actor, or every session from an
 * IdP session, as a back-channel logout names it. Ending a session is not a command: it deletes it
 * from the store, so millions of logouts never become changesets. An actor's sessions and an IdP
 * session's are ended through the set the store keeps of them, never a scan.
 *
 * Each end adds the number of sessions it ended to the counter `cms.session.ended` with its
 * reason, and nothing when there were none.
 */
#[Internal]
final readonly class EndSessions
{
    public function __construct(
        private SessionStore $store,
        private SessionCounters $counters,
    ) {}

    /**
     * Ends the session of the id at a logout. Returns whether it was there.
     */
    public function logout(SessionToken $session): bool
    {
        $ended = $this->store->end(SessionKey::of($session));
        $this->counters->ended(SessionEndReason::Logout, $ended ? 1 : 0);

        return $ended;
    }

    /**
     * Ends every session of the actor and returns how many there were.
     */
    public function ofActor(ActorId $actor): int
    {
        $ended = $this->store->endActor($actor);
        $this->counters->ended(SessionEndReason::Actor, $ended);

        return $ended;
    }

    /**
     * Ends every session from the IdP session and returns how many there were.
     */
    public function ofIdpSession(ConnectionId $connection, IdpSessionId $idpSession): int
    {
        $ended = $this->store->endIdpSession($connection, $idpSession);
        $this->counters->ended(SessionEndReason::IdpSession, $ended);

        return $ended;
    }
}
