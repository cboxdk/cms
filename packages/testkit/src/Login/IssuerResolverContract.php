<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TenantClaim;
use Cbox\Cms\Contracts\Identity\Login\TenantId;
use Cbox\Cms\Contracts\Identity\Login\TenantPin;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for IssuerResolver (GUARDRAILS 2.3 and 9, PRD 5.16 "Koblinger"). The
 * fake and every real resolver run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness that configures a resolver with the pins it is given:
 *
 *     final class FakeIssuerResolverContractTest extends TestCase
 *     {
 *         use IssuerResolverContract;
 *
 *         protected function issuers(): IssuerResolverHarness
 *         {
 *             return new FakeIssuerResolver;
 *         }
 *     }
 *
 * The cases cover the pin a connection has, an unknown connection, a token of the pinned issuer,
 * a token of another issuer, and for a connection pinned to a tenant: a token of that tenant, of
 * another tenant, without the tenant claim, with it empty, with the tenant only in another claim,
 * and two connections to one issuer pinned to two tenants. The checks run in the order of
 * IssuerPin::admit().
 */
#[Experimental]
trait IssuerResolverContract
{
    /**
     * A harness that configures a resolver with exactly the pins it is given.
     */
    abstract protected function issuers(): IssuerResolverHarness;

    #[Test]
    public function a_connection_is_pinned_to_the_issuer_and_tenant_it_was_configured_with(): void
    {
        $resolver = $this->issuers()->resolver($this->googlePin(), $this->entraPin());

        $google = $resolver->pin($this->google());
        $entra = $resolver->pin($this->entra());

        Assert::assertTrue($google->connection->equals($this->google()));
        Assert::assertTrue($google->issuer->equals($this->googleIssuer()));
        Assert::assertNull($google->tenant);
        Assert::assertTrue($entra->issuer->equals($this->entraIssuer()));
        Assert::assertNotNull($entra->tenant);
        Assert::assertTrue($entra->tenant->claim->equals(new TenantClaim('tid')));
        Assert::assertTrue($entra->tenant->tenant->equals($this->acme()));
    }

    #[Test]
    public function a_connection_without_a_pin_is_unknown(): void
    {
        $resolver = $this->issuers()->resolver($this->googlePin());
        $unknown = new ConnectionId('okta');

        $this->expectUnknown(static fn () => $resolver->pin($unknown));
        $this->expectUnknown(fn () => $resolver->admit($unknown, $this->claims($this->googleIssuer())));
    }

    #[Test]
    public function a_token_of_the_pinned_issuer_is_admitted_as_its_idp_identity(): void
    {
        $resolver = $this->issuers()->resolver($this->googlePin());

        $identity = $resolver->admit($this->google(), $this->claims($this->googleIssuer()));

        Assert::assertTrue($identity->equals(new IdpIdentity($this->google(), $this->googleIssuer(), $this->subject())));
    }

    #[Test]
    public function a_token_of_another_issuer_is_refused(): void
    {
        $resolver = $this->issuers()->resolver($this->googlePin(), $this->entraPin());

        $this->expectRefusal(LoginErrorCode::IssuerMismatch, fn () => $resolver->admit($this->google(), $this->claims($this->entraIssuer())));
        $this->expectRefusal(LoginErrorCode::IssuerMismatch, fn () => $resolver->admit($this->google(), $this->claims(new Issuer('https://accounts.google.com/'))));
    }

    #[Test]
    public function a_token_of_another_issuer_is_refused_before_its_tenant_is_read(): void
    {
        $resolver = $this->issuers()->resolver($this->entraPin());

        $this->expectRefusal(LoginErrorCode::IssuerMismatch, fn () => $resolver->admit($this->entra(), $this->claims($this->googleIssuer(), ['tid' => $this->acme()->value])));
        $this->expectRefusal(LoginErrorCode::IssuerMismatch, fn () => $resolver->admit($this->entra(), $this->claims($this->googleIssuer())));
    }

    #[Test]
    public function a_token_of_the_pinned_tenant_is_admitted(): void
    {
        $resolver = $this->issuers()->resolver($this->entraPin());

        $identity = $resolver->admit($this->entra(), $this->claims($this->entraIssuer(), ['tid' => $this->acme()->value, 'name' => 'Ada']));

        Assert::assertTrue($identity->equals(new IdpIdentity($this->entra(), $this->entraIssuer(), $this->subject())));
    }

    #[Test]
    public function a_token_of_another_tenant_is_refused(): void
    {
        $resolver = $this->issuers()->resolver($this->entraPin());

        $this->expectRefusal(LoginErrorCode::TenantMismatch, fn () => $resolver->admit($this->entra(), $this->claims($this->entraIssuer(), ['tid' => $this->globex()->value])));
        $this->expectRefusal(LoginErrorCode::TenantMismatch, fn () => $resolver->admit($this->entra(), $this->claims($this->entraIssuer(), ['tid' => strtoupper($this->acme()->value)])));
    }

    #[Test]
    public function a_token_without_the_tenant_claim_is_refused(): void
    {
        $resolver = $this->issuers()->resolver($this->entraPin());

        $this->expectRefusal(LoginErrorCode::TenantClaimMissing, fn () => $resolver->admit($this->entra(), $this->claims($this->entraIssuer())));
        $this->expectRefusal(LoginErrorCode::TenantClaimMissing, fn () => $resolver->admit($this->entra(), $this->claims($this->entraIssuer(), ['tid' => ''])));
    }

    #[Test]
    public function a_tenant_in_another_claim_than_the_pinned_one_is_not_read(): void
    {
        $resolver = $this->issuers()->resolver($this->entraPin());

        $this->expectRefusal(LoginErrorCode::TenantClaimMissing, fn () => $resolver->admit($this->entra(), $this->claims($this->entraIssuer(), ['hd' => $this->acme()->value, 'tenant' => $this->acme()->value])));
    }

    #[Test]
    public function two_connections_to_one_issuer_each_admit_only_their_own_tenant(): void
    {
        $globex = new ConnectionId('entra-globex');
        $resolver = $this->issuers()->resolver(
            $this->entraPin(),
            new IssuerPin($globex, $this->entraIssuer(), new TenantPin(new TenantClaim('tid'), $this->globex())),
        );
        $fromAcme = $this->claims($this->entraIssuer(), ['tid' => $this->acme()->value]);
        $fromGlobex = $this->claims($this->entraIssuer(), ['tid' => $this->globex()->value]);

        Assert::assertTrue($resolver->admit($this->entra(), $fromAcme)->connection->equals($this->entra()));
        Assert::assertTrue($resolver->admit($globex, $fromGlobex)->connection->equals($globex));
        $this->expectRefusal(LoginErrorCode::TenantMismatch, fn () => $resolver->admit($this->entra(), $fromGlobex));
        $this->expectRefusal(LoginErrorCode::TenantMismatch, fn () => $resolver->admit($globex, $fromAcme));
    }

    #[Test]
    public function a_connection_without_a_tenant_pin_admits_whatever_tenant_the_token_names(): void
    {
        $resolver = $this->issuers()->resolver($this->googlePin());

        $identity = $resolver->admit($this->google(), $this->claims($this->googleIssuer(), ['hd' => 'example.org']));

        Assert::assertTrue($identity->subject->equals($this->subject()));
    }

    private function google(): ConnectionId
    {
        return new ConnectionId('google');
    }

    private function entra(): ConnectionId
    {
        return new ConnectionId('entra-acme');
    }

    private function googleIssuer(): Issuer
    {
        return new Issuer('https://accounts.google.com');
    }

    private function entraIssuer(): Issuer
    {
        return new Issuer('https://login.microsoftonline.com/common/v2.0');
    }

    private function acme(): TenantId
    {
        return new TenantId('9188040d-6c67-4c5b-b112-36a304b66dad');
    }

    private function globex(): TenantId
    {
        return new TenantId('72f988bf-86f1-41af-91ab-2d7cd011db47');
    }

    private function googlePin(): IssuerPin
    {
        return new IssuerPin($this->google(), $this->googleIssuer());
    }

    private function entraPin(): IssuerPin
    {
        return new IssuerPin($this->entra(), $this->entraIssuer(), new TenantPin(new TenantClaim('tid'), $this->acme()));
    }

    private function subject(): Subject
    {
        return new Subject('248289761001');
    }

    /**
     * @param  array<string, string>  $claims
     */
    private function claims(Issuer $issuer, array $claims = []): TokenClaims
    {
        return new TokenClaims($issuer, $this->subject(), $claims);
    }

    /**
     * @param  Closure(): object  $admit
     */
    private function expectRefusal(LoginErrorCode $reason, Closure $admit): void
    {
        try {
            $admit();
        } catch (LoginRefused $refused) {
            Assert::assertSame($reason, $refused->reason);

            return;
        }

        Assert::fail(sprintf('The token was admitted; it must be refused with %s.', $reason->value));
    }

    /**
     * @param  Closure(): object  $call
     */
    private function expectUnknown(Closure $call): void
    {
        try {
            $call();
        } catch (UnknownConnection $unknown) {
            Assert::assertStringContainsString('okta', $unknown->getMessage());

            return;
        }

        Assert::fail('An unknown connection was resolved; it must throw UnknownConnection.');
    }
}
