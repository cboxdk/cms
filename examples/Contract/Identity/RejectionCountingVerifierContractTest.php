<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Testkit\Identity\ActorDirectoryContract;
use Cbox\Cms\Testkit\Identity\CredentialVerifierContract;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared identity suites against RejectionCountingVerifier, through the harness
 * RejectionCountingIdentity. In the application the decorated verifier is the default,
 * PostgresCredentialVerifier; here it is the testkit's FakeIdentity, so the suites run without
 * services. The class also tests what the decorator adds.
 */
final class RejectionCountingVerifierContractTest extends TestCase
{
    use ActorDirectoryContract;
    use CredentialVerifierContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return new RejectionCountingIdentity(new FakeIdentity($clock));
    }

    #[Test]
    public function the_decorator_counts_every_refusal_by_its_reason(): void
    {
        $identity = new RejectionCountingIdentity(new FakeIdentity);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, new DateTimeImmutable('2100-01-01T00:00:00Z')));
        $verifier = $identity->verifier();

        $verifier->verify($credential);
        $verifier->verify(null);

        foreach ([new TransportCredential('not a token'), new TransportCredential('')] as $malformed) {
            try {
                $verifier->verify($malformed);
            } catch (CredentialRejected) {
                // Counted by the decorator.
            }
        }

        self::assertSame(
            [2, 0],
            [$verifier->refusals(CredentialErrorCode::Malformed), $verifier->refusals(CredentialErrorCode::Unknown)],
        );
    }
}
