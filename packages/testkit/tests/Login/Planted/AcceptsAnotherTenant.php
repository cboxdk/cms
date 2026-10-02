<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Login\Planted;

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\TenantPin;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Override;

/**
 * A planted resolver that checks the issuer and that the tenant claim is there, but not which
 * tenant it names: it admits a token of any tenant of the issuer.
 */
final readonly class AcceptsAnotherTenant implements IssuerResolver
{
    private FakeIssuerResolver $pins;

    public function __construct(IssuerPin ...$pins)
    {
        $this->pins = new FakeIssuerResolver(...$pins);
    }

    #[Override]
    public function pin(ConnectionId $connection): IssuerPin
    {
        return $this->pins->pin($connection);
    }

    #[Override]
    public function admit(ConnectionId $connection, TokenClaims $claims): IdpIdentity
    {
        $pin = $this->pin($connection);

        if (! $claims->issuer->equals($pin->issuer)) {
            throw LoginRefused::because(LoginErrorCode::IssuerMismatch);
        }

        if ($pin->tenant instanceof TenantPin && $claims->claim($pin->tenant->claim) === null) {
            throw LoginRefused::because(LoginErrorCode::TenantClaimMissing);
        }

        return new IdpIdentity($connection, $pin->issuer, $claims->subject);
    }
}
