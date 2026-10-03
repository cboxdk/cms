<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\ClassPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\SessionLifetimes;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use DateTimeImmutable;

/**
 * The login policy's decision that a login may get a session (PRD 5.16, "Loginpolitik"). A session
 * is issued only with a LoginDecision, and only the policy check, decide(), constructs one: the
 * constructor is private, so PHPStan refuses a login path that makes its own (the fixture in
 * packages/identity/tests/Phpstan).
 *
 * The decision carries what the session stores: the actor, its class and its credential
 * generation, the connection, the login method, the factors the login gave, when the person
 * authenticated, the identity provider's session id of the login or null, and the session's
 * lifetimes from the policy of the actor's class. The session keeps the factors, so a policy that
 * later requires more refuses it at its next request (SessionRules).
 */
#[Internal]
final readonly class LoginDecision
{
    private function __construct(
        public ActorId $actor,
        public ActorClass $actorClass,
        public CredentialGeneration $credentialGeneration,
        public ConnectionId $connection,
        public LoginMethod $method,
        public LocalFactors $factors,
        public DateTimeImmutable $authTime,
        public SessionLifetimes $lifetimes,
        public ?IdpSessionId $idpSession = null,
    ) {}

    /**
     * The policy check. It decides in this order, and refuses with the first rule that fails:
     *
     * 1. the actor exists (actor_not_active otherwise);
     * 2. its class logs in: a service actor never does (login_class_not_allowed);
     * 3. it is active, not pending, deactivated or deprovisioned (actor_not_active);
     * 4. the policy of its class lists the connection (login_connection_not_allowed);
     * 5. the policy lists the method, and the method belongs to the connection: every method but
     *    federated to the local connection, federated to every other (login_method_not_allowed);
     * 6. for the local connection, local login is switched on (login_local_disabled), and the actor
     *    is linked to no authoritative connection, read through $links (login_authoritative_link,
     *    invariant 38);
     * 7. the login gave the factors the policy requires (login_factors_unavailable).
     *
     * $actor is the actor of the attempt as the ActorDirectory read it, null when it does not exist.
     *
     * @throws LoginPolicyRefused
     */
    public static function decide(LoginPolicy $policy, ?Actor $actor, LoginAttempt $attempt, IdpLinks $links): self
    {
        if (! $actor instanceof Actor) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ActorNotActive);
        }

        $connection = $attempt->assertion->connection;
        $class = self::admit($policy, $actor, $attempt->actor, $connection, $attempt->method, $links);
        $local = LoginPolicy::isLocal($connection);

        $factors = self::factorsGiven($attempt);

        if (! ($local ? $factors->satisfies($class->localFactors) : self::federatedFactorsGiven($class, $attempt->assertion))) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::FactorsUnavailable);
        }

        return new self(
            $actor->id,
            $actor->class,
            $actor->credentialGeneration,
            $connection,
            $attempt->method,
            $factors,
            $attempt->assertion->authTime,
            $class->lifetimes,
            $attempt->idpSession,
        );
    }

    /**
     * Rules 1 to 6 of decide() for a login by $method on the local connection, asked before a
     * local login path changes anything for the actor, such as a password reset, which sets a
     * password before it can assert a login (PRD 5.16, invariant 38). It makes no decision, so
     * rule 7, the factors, which only the login itself gives, is left to decide().
     *
     * @throws LoginPolicyRefused
     */
    public static function admitsLocal(LoginPolicy $policy, ?Actor $actor, ActorId $id, LoginMethod $method, IdpLinks $links): void
    {
        if (! $actor instanceof Actor) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ActorNotActive);
        }

        self::admit($policy, $actor, $id, new ConnectionId(LoginPolicy::LOCAL_CONNECTION), $method, $links);
    }

    /**
     * Rules 1 to 6 of decide(), and the policy of the actor's class they found.
     *
     * @throws LoginPolicyRefused
     */
    private static function admit(LoginPolicy $policy, Actor $actor, ActorId $id, ConnectionId $connection, LoginMethod $method, IdpLinks $links): ClassPolicy
    {
        if (! $actor->id->equals($id)) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ActorNotActive);
        }

        $class = $policy->of($actor->class);

        if (! $class instanceof ClassPolicy) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ClassNotAllowed);
        }

        if (! $actor->isActive()) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ActorNotActive);
        }

        $local = LoginPolicy::isLocal($connection);

        if (! $class->allowsConnection($connection)) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::ConnectionNotAllowed);
        }

        if (! $class->allowsMethod($method) || $method->isLocal() !== $local) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::MethodNotAllowed);
        }

        if ($local && ! $class->localLogin) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::LocalDisabled);
        }

        if ($local && array_any($links->connectionsOf($actor->id), $policy->isAuthoritative(...))) {
            throw LoginPolicyRefused::because(LoginPolicyErrorCode::AuthoritativeLink);
        }

        return $class;
    }

    /**
     * The factors the login gave: a passkey is a factor of its own and user verification;
     * otherwise two factors show as mfa in the amr claim or as two methods there (RFC 8176), and
     * anything less is a single factor.
     */
    private static function factorsGiven(LoginAttempt $attempt): LocalFactors
    {
        return $attempt->method === LoginMethod::Passkey
            || $attempt->assertion->authenticatedWith(new AuthenticationMethod('mfa'))
            || count($attempt->assertion->amr) >= 2
            ? LocalFactors::PasskeyOrTwoFactors
            : LocalFactors::Password;
    }

    /**
     * With MFA required, the assertion names one of the policy's amr values or one of its acr
     * values.
     */
    private static function federatedFactorsGiven(ClassPolicy $class, VerifiedAssertion $assertion): bool
    {
        if ($class->federatedAmr === [] && $class->federatedAcr === []) {
            return true;
        }

        $acr = $assertion->acr;

        return array_any($class->federatedAmr, $assertion->authenticatedWith(...))
            || ($acr instanceof AuthenticationContext && array_any($class->federatedAcr, $acr->equals(...)));
    }
}
