<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Identity\CredentialVerifierContract;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared CredentialVerifier contract suite against the in-memory fake.
 */
final class FakeCredentialVerifierContractTest extends TestCase
{
    use CredentialVerifierContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return new FakeIdentity($clock);
    }
}
