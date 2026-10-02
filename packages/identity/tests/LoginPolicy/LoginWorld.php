<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LoginPolicy;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\Tests\LoginPolicy\Fakes\FakeIdpLinks;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;

/**
 * CheckLoginPolicy over fakes: the actor directory of FakeIdentity, the IdP links of FakeIdpLinks
 * and FakeTelemetry, with the policy a test gives it.
 */
final class LoginWorld
{
    public const string NOW = '2026-10-02T09:00:00Z';

    public FakeIdentity $identity;

    public FakeIdpLinks $links;

    public FakeTelemetry $telemetry;

    public function __construct(public LoginPolicy $policy)
    {
        $this->identity = new FakeIdentity(new FakeClock(new DateTimeImmutable(self::NOW)));
        $this->links = new FakeIdpLinks;
        $this->telemetry = new FakeTelemetry;
    }

    public function check(): CheckLoginPolicy
    {
        return new CheckLoginPolicy($this->policy, $this->identity, $this->links, $this->telemetry);
    }

    public function actor(ActorClass $class = ActorClass::Staff, ActorState $state = ActorState::Active): Actor
    {
        return $this->identity->addActor($class, $state);
    }
}
