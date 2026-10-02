<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A setting of the password reset in `cbox-cms.identity.password_reset` is invalid (PRD 5.16). The
 * message names the key and what it must be, never the value.
 */
#[Internal]
final class InvalidPasswordReset extends LogicException
{
    public static function key(string $key, string $expected): self
    {
        return new self(sprintf('The password reset setting %s is invalid: it must be %s.', $key, $expected));
    }
}
