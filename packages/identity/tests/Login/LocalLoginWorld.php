<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\Login\Actions\LogInLocally;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleLimit;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;
use Cbox\Cms\Identity\Sessions\Adapter\SessionCredentialVerifier;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use Cbox\Cms\Identity\Tests\LocalAccounts\CountingPasswordHasher;
use Cbox\Cms\Identity\Tests\Login\Fakes\FakeLoginThrottle;
use Cbox\Cms\Identity\Tests\LoginPolicy\Fakes\FakeIdpLinks;
use Cbox\Cms\Identity\Tests\Sessions\Fakes\FakeSessionStore;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * A local login over fakes (GUARDRAILS 9): FakeIdentity as the actor directory and as the verifier
 * the session verifier decorates, FakeLocalCredentialStore with the real Argon2id hasher at cheap
 * parameters, the login policy with password logins for staff, as the workbench sets it,
 * FakeLoginThrottle at the module's default limits, FakeSessionStore, FakeTelemetry and one
 * FakeClock for all of them.
 *
 * action() is LogInLocally over them; into() binds them in a container, so the panel's routes log
 * in and verify sessions through them.
 */
final class LocalLoginWorld
{
    public const string NOW = '2026-10-03T09:00:00Z';

    public const string PASSWORD = 'correct horse battery staple';

    public FakeClock $clock;

    public FakeIdentity $identity;

    public FakeLocalCredentialStore $accounts;

    public CountingPasswordHasher $hasher;

    public FakeSessionStore $sessions;

    public FakeLoginThrottle $throttle;

    public FakeTelemetry $telemetry;

    public LoginPolicy $policy;

    public LocalConnection $connection;

    public function __construct()
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->identity = new FakeIdentity($this->clock);
        $this->accounts = new FakeLocalCredentialStore($this->clock, $this->identity);
        $this->hasher = new CountingPasswordHasher;
        $this->sessions = new FakeSessionStore($this->clock);
        $this->throttle = new FakeLoginThrottle(self::settings(), $this->clock);
        $this->telemetry = new FakeTelemetry;
        $this->policy = SessionWorld::policy();
        $this->connection = new LocalConnection($this->accounts, $this->hasher, new Issuer('http://localhost'), $this->clock);
    }

    /**
     * The module's default limits: 5 attempts per identifier and 50 per IP address in 15 minutes.
     */
    public static function settings(): LoginThrottleSettings
    {
        return new LoginThrottleSettings(new ThrottleLimit(5, 900), new ThrottleLimit(50, 900));
    }

    /**
     * An actor of the class and state with a local account of the email and PASSWORD.
     */
    public function person(string $email, ActorState $state = ActorState::Active, ActorClass $class = ActorClass::Staff): Actor
    {
        $actor = $this->identity->addActor($class, $state);
        $this->accounts->bind($actor->id, new LoginIdentifier($email), $this->hasher->hash(new Password(self::PASSWORD)));

        return $actor;
    }

    public function action(): LogInLocally
    {
        $counters = new SessionCounters($this->telemetry);

        return new LogInLocally(
            $this->connection,
            new CheckLoginPolicy($this->policy, $this->identity, new FakeIdpLinks, $this->telemetry),
            $this->throttle,
            new IssueSession($this->sessions, $this->clock, $counters),
            new EndSessions($this->sessions, $counters),
            $this->telemetry,
        );
    }

    public function verifier(): SessionCredentialVerifier
    {
        return new SessionCredentialVerifier($this->identity, $this->sessions, $this->identity, $this->policy, new FakeIdpLinks, $this->clock, new SessionCounters($this->telemetry));
    }

    /**
     * Binds the world's parts in the container, the verifier of sessions as the CredentialVerifier.
     */
    public function into(Container $container): void
    {
        $container->instance(Clock::class, $this->clock);
        $container->instance(Telemetry::class, $this->telemetry);
        $container->instance(ActorDirectory::class, $this->identity);
        $container->instance(LoginPolicy::class, $this->policy);
        $container->instance(IdpLinks::class, new FakeIdpLinks);
        $container->instance(SessionStore::class, $this->sessions);
        $container->instance(LoginThrottle::class, $this->throttle);
        $container->instance(LocalConnection::class, $this->connection);
        $container->instance(CredentialVerifier::class, $this->verifier());
    }
}
