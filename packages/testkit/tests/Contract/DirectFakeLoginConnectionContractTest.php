<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Cbox\Cms\Testkit\Login\FakeLoginAccount;
use Cbox\Cms\Testkit\Login\FakeLoginConnection;
use Cbox\Cms\Testkit\Login\LoginConnectionContract;
use Cbox\Cms\Testkit\Login\LoginConnectionHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared LoginConnection contract suite against the fake of the flow Direct, as a local
 * account's password check: no groups, no context.
 */
final class DirectFakeLoginConnectionContractTest extends TestCase
{
    use LoginConnectionContract;

    #[Override]
    protected function login(): LoginConnectionHarness
    {
        $local = new ConnectionId('local');
        $issuer = new Issuer('https://cms.example.org');
        $issuers = new FakeIssuerResolver(new IssuerPin($local, $issuer));

        return new FakeLoginConnection($local, LoginFlow::Direct, $issuers)
            ->enrol(new FakeLoginAccount('ada@example.org', 'correct horse battery', new TokenClaims($issuer, new Subject('ada')), [new AuthenticationMethod('pwd')]))
            ->enrol(new FakeLoginAccount('grace@example.org', 'another long secret', new TokenClaims($issuer, new Subject('grace'))));
    }
}
