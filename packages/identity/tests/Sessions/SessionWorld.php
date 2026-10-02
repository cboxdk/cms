<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Sessions;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;
use Cbox\Cms\Identity\Sessions\Adapter\SessionCredentialVerifier;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Tests\LoginPolicy\Fakes\FakeIdpLinks;
use Cbox\Cms\Identity\Tests\Sessions\Fakes\FakeSessionStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;
use Illuminate\Config\Repository;

/**
 * Sessions over fakes: FakeIdentity as the actor directory and as the verifier the session
 * verifier decorates, FakeSessionStore, FakeTelemetry and one FakeClock for all of them. A session
 * is issued as a login path issues it, with the decision of CheckLoginPolicy under the policy the
 * world holds; a test changes the policy with policy(), as an environment's configuration would
 * change between two requests.
 */
final class SessionWorld
{
    public const string NOW = '2026-10-02T09:00:00Z';

    public FakeClock $clock;

    public FakeIdentity $identity;

    public FakeSessionStore $store;

    public FakeTelemetry $telemetry;

    public LoginPolicy $policy;

    /**
     * @param  array<array-key, mixed>  $policy  changes to the module's default policy
     * @param  FakeClock|null  $clock  the clock of every part, NOW by default
     */
    public function __construct(array $policy = [], ?FakeClock $clock = null)
    {
        $this->clock = $clock ?? new FakeClock(new DateTimeImmutable(self::NOW));
        $this->identity = new FakeIdentity($this->clock);
        $this->store = new FakeSessionStore($this->clock);
        $this->telemetry = new FakeTelemetry;
        $this->policy = self::policy($policy);
    }

    /**
     * The module's default policy with local password logins for staff, as the workbench sets it,
     * and $changes merged over it as an application's configuration would be.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function policy(array $changes = []): LoginPolicy
    {
        /** @var array<string, mixed> $module */
        $module = require __DIR__.'/../../config/identity.php';
        $policy = array_replace_recursive(['staff' => ['local_factors' => 'password']], $changes);

        return LoginPolicyConfig::read(new Repository(['cbox-cms' => ['identity' => array_replace_recursive($module, ['policy' => $policy])]]));
    }

    public function actor(ActorClass $class = ActorClass::Staff, ActorState $state = ActorState::Active): Actor
    {
        return $this->identity->addActor($class, $state);
    }

    /**
     * Logs the actor in through the policy and issues the session.
     */
    public function login(Actor $actor, LoginMethod $method = LoginMethod::Password, string $connection = 'local', ?string $idpSession = null): NewSession
    {
        $assertion = new VerifiedAssertion(
            new ConnectionId($connection),
            new Issuer($connection === 'local' ? 'http://localhost' : 'https://login.example.test'),
            new Subject('subject-1'),
            $this->clock->now(),
            [new AuthenticationMethod($method === LoginMethod::Passkey ? 'hwk' : 'pwd')],
        );
        $decision = new CheckLoginPolicy($this->policy, $this->identity, new FakeIdpLinks, $this->telemetry)
            ->check(new LoginAttempt($actor->id, $method, $assertion, $idpSession === null ? null : new IdpSessionId($idpSession)));

        return new IssueSession($this->store, $this->clock, $this->counters())->issue($decision);
    }

    public function verifier(): SessionCredentialVerifier
    {
        return new SessionCredentialVerifier($this->identity, $this->store, $this->identity, $this->policy, $this->clock, $this->counters());
    }

    public function ends(): EndSessions
    {
        return new EndSessions($this->store, $this->counters());
    }

    public static function credential(NewSession $session): TransportCredential
    {
        return $session->token->credential();
    }

    private function counters(): SessionCounters
    {
        return new SessionCounters($this->telemetry);
    }
}
