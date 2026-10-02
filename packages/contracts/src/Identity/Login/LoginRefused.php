<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A login connection or an issuer resolver refused a login, for $reason. The message never holds a
 * secret, a state, a token or a claim's value, so it can be logged.
 */
#[Experimental]
final class LoginRefused extends RuntimeException
{
    private function __construct(public readonly LoginErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(LoginErrorCode $reason): self
    {
        return new self($reason, sprintf('The login was refused: %s.', match ($reason) {
            LoginErrorCode::StateMismatch => 'the response does not belong to the pending login',
            LoginErrorCode::Rejected => 'the identity provider or the credential check did not accept it',
            LoginErrorCode::IssuerMismatch => 'the token is from another issuer than the connection is pinned to',
            LoginErrorCode::TenantClaimMissing => 'the connection is pinned to a tenant and the token does not carry the tenant claim',
            LoginErrorCode::TenantMismatch => 'the token is from another tenant than the connection is pinned to',
        }));
    }
}
