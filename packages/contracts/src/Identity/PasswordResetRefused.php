<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A LocalCredentialStore refused a password reset (PRD 5.16): the token is unknown, used already
 * or expired. The three are one refusal, so a caller cannot tell which tokens exist. Nothing was
 * written. The message never holds the token.
 */
#[Experimental]
final class PasswordResetRefused extends RuntimeException
{
    public const string CODE = 'password_reset_token_invalid';

    public static function token(): self
    {
        return new self('The password reset token is unknown, used already or expired.');
    }
}
