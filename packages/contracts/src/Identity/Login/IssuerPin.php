<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a connection is pinned to (PRD 5.16, "Koblinger"): its issuer and, for an issuer with
 * several tenants, its tenant.
 *
 * admit() decides whether the claims of a verified token belong to the connection, and gives their
 * IdP identity. It refuses, in this order and with the first that applies:
 *
 * 1. a token from another issuer (login_issuer_mismatch);
 * 2. for a pin with a tenant, a token without the tenant claim, or with it empty
 *    (login_tenant_claim_missing), whatever other claim carries the tenant;
 * 3. for a pin with a tenant, a token of another tenant (login_tenant_mismatch).
 *
 * Every IssuerResolver decides through it, so the fake and a real resolver cannot differ in the
 * rules.
 */
#[Experimental]
final readonly class IssuerPin
{
    public function __construct(public ConnectionId $connection, public Issuer $issuer, public ?TenantPin $tenant = null) {}

    /**
     * @throws LoginRefused when the token is not from the pinned issuer and tenant
     */
    public function admit(TokenClaims $claims): IdpIdentity
    {
        if (! $claims->issuer->equals($this->issuer)) {
            throw LoginRefused::because(LoginErrorCode::IssuerMismatch);
        }

        if ($this->tenant instanceof TenantPin) {
            $tenant = $claims->claim($this->tenant->claim);

            if ($tenant === null) {
                throw LoginRefused::because(LoginErrorCode::TenantClaimMissing);
            }

            if ($tenant !== $this->tenant->tenant->value) {
                throw LoginRefused::because(LoginErrorCode::TenantMismatch);
            }
        }

        return new IdpIdentity($this->connection, $this->issuer, $claims->subject);
    }
}
