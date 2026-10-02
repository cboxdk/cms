<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\ClassPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\SessionLifetimes;
use Cbox\Cms\Identity\Sessions\Domain\Dto\StoredSession;
use DateInterval;
use DateTimeImmutable;

/**
 * When a session ends and whether it still verifies (PRD 5.16, "Loginpolitik" and "Sessioner og
 * credentials").
 *
 * A session ends $inactivityMinutes after the request that last saw it, and $absoluteMinutes after
 * it was issued whatever happens: each request renews it, sliding the inactivity end, but never
 * past the absolute end. The lifetimes are those of the login policy of the actor's class now, so a
 * shortened lifetime applies to the sessions already issued.
 *
 * The store keeps a session KEPT_AFTER_END_MINUTES past its end (keptUntil()), so a request just
 * after it ended is told credential_expired, and the end is counted with the reason expired,
 * instead of finding nothing; the rules, not the store, decide that it ended.
 */
#[Internal]
final readonly class SessionRules
{
    public const int KEPT_AFTER_END_MINUTES = 10;

    /**
     * When the session ends unless a request renews it: the earlier of its inactivity end and its
     * absolute end.
     */
    public static function expiresAt(StoredSession $session, SessionLifetimes $lifetimes): DateTimeImmutable
    {
        $idle = $session->lastSeenAt->add(new DateInterval(sprintf('PT%dM', $lifetimes->inactivityMinutes)));
        $absolute = $session->issuedAt->add(new DateInterval(sprintf('PT%dM', $lifetimes->absoluteMinutes)));

        return min($idle, $absolute);
    }

    /**
     * How long the store keeps the session: KEPT_AFTER_END_MINUTES past expiresAt().
     */
    public static function keptUntil(StoredSession $session, SessionLifetimes $lifetimes): DateTimeImmutable
    {
        return self::expiresAt($session, $lifetimes)->add(new DateInterval(sprintf('PT%dM', self::KEPT_AFTER_END_MINUTES)));
    }

    /**
     * The principal of the session at $now, given the actor as the ActorDirectory read it, null
     * when it does not exist, and the login policy. It refuses, in the order of CredentialVerifier,
     * with the first rule that fails:
     *
     * 1. the policy has the actor's class, whose lifetimes the session is judged by; a class that
     *    never logs in has no policy, and its session is credential_not_allowed at once;
     * 2. $now is before the session's end, expiresAt() (credential_expired);
     * 3. the actor exists and is active (actor_not_active), and its credential generation is not
     *    above the session's (credential_revoked), as IssuedSession::principal() decides;
     * 4. the policy of the actor's class still allows the session's connection and its login
     *    method, the method still belongs to the connection, and for the local connection local
     *    login is still switched on (credential_not_allowed).
     *
     * @throws CredentialRejected
     */
    public static function principal(StoredSession $session, ?Actor $actor, LoginPolicy $policy, DateTimeImmutable $now): ActorPrincipal
    {
        $class = $policy->of($session->actorClass);

        if (! $class instanceof ClassPolicy) {
            throw CredentialRejected::because(CredentialErrorCode::NotAllowed);
        }

        if ($now >= self::expiresAt($session, $class->lifetimes)) {
            throw CredentialRejected::because(CredentialErrorCode::Expired);
        }

        if (! $actor instanceof Actor || ! $actor->id->equals($session->actor)) {
            throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
        }

        $principal = $session->issued()->principal($actor);

        if (! self::allowed($session, $class)) {
            throw CredentialRejected::because(CredentialErrorCode::NotAllowed);
        }

        return $principal;
    }

    private static function allowed(StoredSession $session, ClassPolicy $class): bool
    {
        $local = LoginPolicy::isLocal($session->connection);

        return $class->allowsConnection($session->connection)
            && $class->allowsMethod($session->method)
            && $session->method->isLocal() === $local
            && (! $local || $class->localLogin);
    }
}
