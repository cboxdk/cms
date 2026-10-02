<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The claims of a token a connection has verified (PRD 5.16): its issuer, its subject, and the
 * other claims whose value is text, by name, such as tid or hd. A connection builds it only after it
 * has checked the token's signature, audience, expiry and nonce, and only from the token, never
 * from the request, so a tenant can never come from a parameter the browser sent.
 *
 * A connection refuses a token whose iss is not an Issuer as login_issuer_mismatch, since it cannot
 * be the issuer the connection is pinned to.
 */
#[Experimental]
final readonly class TokenClaims
{
    /**
     * @param  array<string, string>  $claims  the token's other claims whose value is text
     */
    public function __construct(public Issuer $issuer, public Subject $subject, public array $claims = []) {}

    /**
     * The text of the claim, or null when the token does not carry it or carries it empty.
     */
    public function claim(TenantClaim $name): ?string
    {
        $value = $this->claims[$name->value] ?? null;

        return $value === null || $value === '' ? null : $value;
    }
}
