<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginDecision;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionRules;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;

/**
 * Issues a session (PRD 5.16), the human credential of the panel. It takes only a LoginDecision,
 * which only the login policy's check makes, so no login path issues a session the policy did not
 * allow.
 *
 * Every session gets a new id of 256 random bits, SessionToken, never one the request carried, so
 * a session id planted before the login is never the one that logs in (no fixation). The store
 * keeps it under the SHA-256 of the id with what the decision says: the actor, its class and its
 * credential generation, the connection, the login method and the IdP session id, issued and last
 * seen at the Clock's time, until the end of its lifetimes (SessionRules::keptUntil()). Each issue adds 1 to the counter
 * `cms.session.issued` with its login method.
 */
#[Internal]
final readonly class IssueSession
{
    public function __construct(
        private SessionStore $store,
        private Clock $clock,
        private SessionCounters $counters,
    ) {}

    public function issue(LoginDecision $decision): NewSession
    {
        $now = $this->clock->now();
        $token = SessionToken::fromSecret(random_bytes(SessionToken::SECRET_BYTES));
        $session = new StoredSession(
            SessionKey::of($token),
            $decision->actor,
            $decision->actorClass,
            $decision->connection,
            $decision->method,
            $decision->idpSession,
            $decision->credentialGeneration,
            $now,
            $now,
        );
        $expiresAt = SessionRules::expiresAt($session, $decision->lifetimes);

        $this->store->put($session, SessionRules::keptUntil($session, $decision->lifetimes));
        $this->counters->issued($decision->method);

        return new NewSession($token, $session, $expiresAt);
    }
}
