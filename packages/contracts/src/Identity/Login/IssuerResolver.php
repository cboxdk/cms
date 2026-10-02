<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Pins each login connection to its issuer and, for an issuer with several tenants, to its tenant
 * (PRD 5.16, "Koblinger"). A connection asks it before it builds a VerifiedAssertion from a token.
 *
 * A token from another issuer or tenant is refused. The tenant is read from the claim of the
 * token the pin names, such as tid for Microsoft Entra ID or hd for Google, never from a parameter
 * of the request, and a token without that claim is refused. admit() decides through
 * IssuerPin::admit(), so every resolver applies the same rules in the same order.
 */
#[Experimental]
interface IssuerResolver
{
    /**
     * The issuer, and tenant, the connection is pinned to.
     *
     * @throws UnknownConnection when no pin names the connection
     */
    public function pin(ConnectionId $connection): IssuerPin;

    /**
     * The IdP identity of a verified token's claims for the connection.
     *
     * @throws UnknownConnection when no pin names the connection
     * @throws LoginRefused when the token is from another issuer or tenant, or lacks the tenant claim
     */
    public function admit(ConnectionId $connection, TokenClaims $claims): IdpIdentity;
}
