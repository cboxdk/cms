<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialForm;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\ClassPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionEndReason;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Sessions\Domain\SessionRules;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use Override;

/**
 * The verifier of sessions (PRD 5.16), which the identity module puts in front of the bound
 * CredentialVerifier with the container's extend(), so the core never names the identity module.
 *
 * A credential in the session form is verified here; every other call, no credential and a bearer
 * token included, goes to the verifier it decorates unchanged. A session id is parsed first, and
 * one out of its form is refused without a lookup (credential_malformed). Then the session is read
 * from the SessionStore by the SHA-256 of its id (credential_unknown when it is not there), the
 * actor through the ActorDirectory, whose state and generation are in Postgres, and
 * SessionRules::principal() decides at the Clock's time with the login policy as it is now: expired
 * (credential_expired), actor not active (actor_not_active), revoked (credential_revoked) or no
 * longer allowed by the policy (credential_not_allowed). A refused session is ended, and counted in
 * `cms.session.ended` with its reason. A session that verifies is renewed: its last-seen time moves
 * to now, and its end slides with it within its absolute lifetime. When it was ended meanwhile, the
 * renewal finds nothing, and it is refused as credential_unknown.
 *
 * The principal is an ActorPrincipal of issuer kind Human on behalf of no one. When the store
 * cannot be reached, the error is thrown and the request is refused: a session never verifies
 * without its store.
 */
#[Internal]
final readonly class SessionCredentialVerifier implements CredentialVerifier
{
    public function __construct(
        private CredentialVerifier $verifier,
        private SessionStore $store,
        private ActorDirectory $actors,
        private LoginPolicy $policy,
        private Clock $clock,
        private SessionCounters $counters,
    ) {}

    #[Override]
    public function verify(?TransportCredential $credential): Principal
    {
        if (! $credential instanceof TransportCredential || $credential->form !== CredentialForm::Session) {
            return $this->verifier->verify($credential);
        }

        $key = SessionKey::of(SessionToken::parse($credential));
        $session = $this->store->find($key) ?? throw CredentialRejected::because(CredentialErrorCode::Unknown);
        $now = $this->clock->now();

        try {
            $principal = SessionRules::principal($session, $this->actors->find($session->actor), $this->policy, $now);
        } catch (CredentialRejected $rejected) {
            $reason = SessionEndReason::refused($rejected->reason);

            if ($reason instanceof SessionEndReason && $this->store->end($key)) {
                $this->counters->ended($reason, 1);
            }

            throw $rejected;
        }

        $class = $this->policy->of($session->actorClass);
        $seen = $session->seenAt($now);

        if (! $class instanceof ClassPolicy || ! $this->store->touch($seen, SessionRules::keptUntil($seen, $class->lifetimes))) {
            throw CredentialRejected::because(CredentialErrorCode::Unknown);
        }

        return $principal;
    }
}
