<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Identity\Tests\Sessions\SessionVerifierIdentity;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\CredentialVerifierContract;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\SessionCredentialContract;
use Cbox\Cms\Testkit\Identity\SessionIdentityHarness;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared CredentialVerifier suites against the session verifier the identity module puts in
 * front of the bound verifier: every case of CredentialVerifierContract goes through the decorator
 * to the verifier it decorates, and SessionCredentialContract's sessions are issued through the
 * login policy and held in the session store's fake.
 */
final class SessionCredentialVerifierContractTest extends TestCase
{
    use CredentialVerifierContract;
    use SessionCredentialContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return $this->sessionIdentity($clock);
    }

    #[Override]
    protected function sessionIdentity(Clock $clock): SessionIdentityHarness
    {
        if (! $clock instanceof FakeClock) {
            throw new LogicException('The session world runs on a FakeClock.');
        }

        return new SessionVerifierIdentity(new SessionWorld(clock: $clock));
    }
}
