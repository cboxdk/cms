<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Testkit\Identity\CredentialVerifierContract;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\SessionCredentialContract;
use Cbox\Cms\Testkit\Identity\SessionIdentityHarness;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared CredentialVerifier contract suites against the in-memory fake, which holds sessions
 * as well as service credentials.
 */
final class FakeCredentialVerifierContractTest extends TestCase
{
    use CredentialVerifierContract;
    use SessionCredentialContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return new FakeIdentity($clock);
    }

    #[Override]
    protected function sessionIdentity(Clock $clock): SessionIdentityHarness
    {
        return new FakeIdentity($clock);
    }

    #[Test]
    public function it_looks_a_session_up_only_when_its_id_is_in_the_form(): void
    {
        $identity = new FakeIdentity;
        $session = $identity->startSession($identity->addActor(ActorClass::Staff)->id);

        try {
            $identity->verify(TransportCredential::session('cms_ss_not-a-session'));
        } catch (CredentialRejected) {
            // Refused without a lookup.
        }

        self::assertSame(0, $identity->lookups());

        $identity->verify($session);

        self::assertSame(1, $identity->lookups());
    }
}
