<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TenantClaim;
use Cbox\Cms\Contracts\Identity\Login\TenantId;
use Cbox\Cms\Contracts\Identity\Login\TenantPin;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Cbox\Cms\Testkit\Login\FakeLoginAccount;
use Cbox\Cms\Testkit\Login\FakeLoginConnection;
use Cbox\Cms\Testkit\Login\LoginConnectionContract;
use Cbox\Cms\Testkit\Login\LoginConnectionHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared LoginConnection contract suite against the fake of the flow Redirect, as an OpenID
 * Connect connection to an issuer with several tenants, pinned to one, that sends methods, a
 * context and groups.
 */
final class FakeLoginConnectionContractTest extends TestCase
{
    use LoginConnectionContract;

    #[Override]
    protected function login(): LoginConnectionHarness
    {
        $entra = new ConnectionId('entra-acme');
        $issuer = new Issuer('https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0');
        $tenant = new TenantId('9188040d-6c67-4c5b-b112-36a304b66dad');
        $issuers = new FakeIssuerResolver(new IssuerPin($entra, $issuer, new TenantPin(new TenantClaim('tid'), $tenant)));

        return new FakeLoginConnection($entra, LoginFlow::Redirect, $issuers)->enrol(new FakeLoginAccount(
            'ada@acme.example',
            'unused for a redirect',
            new TokenClaims($issuer, new Subject('AAAAAAAAAAAAAAAAAAAAAIkzqFVrSaSaFHy782bbtaQ'), ['tid' => $tenant->value]),
            [new AuthenticationMethod('pwd'), new AuthenticationMethod('mfa')],
            new AuthenticationContext('c1'),
            [new IdpGroup('e3b0c442-98fc-4c1c-9a0d-7d5f1a2b3c4d'), new IdpGroup('Editors')],
        ));
    }
}
