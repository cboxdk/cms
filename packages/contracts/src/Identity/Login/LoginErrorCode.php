<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a login was refused (PRD 5.16). Each is a code of the error catalog,
 * Cbox\Cms\Contracts\Errors\ErrorCode.
 */
#[Experimental]
enum LoginErrorCode: string
{
    /** The response does not belong to the pending login: another state, another connection or another flow. */
    case StateMismatch = 'login_state_mismatch';

    /** The identity provider or the credential check said no: a wrong secret, an unknown account or an error from the provider. */
    case Rejected = 'login_rejected';

    /** The token is from another issuer than the one the connection is pinned to. */
    case IssuerMismatch = 'login_issuer_mismatch';

    /** The connection is pinned to a tenant, and the token does not carry the tenant claim. */
    case TenantClaimMissing = 'login_tenant_claim_missing';

    /** The connection is pinned to a tenant, and the token's tenant claim names another. */
    case TenantMismatch = 'login_tenant_mismatch';
}
