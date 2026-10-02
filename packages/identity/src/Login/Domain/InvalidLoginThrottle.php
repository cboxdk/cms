<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * The limits of the login throttle in `cbox-cms.identity.login.throttle` are invalid (PRD 5.16).
 * The message names the key and what it must be.
 */
#[Internal]
final class InvalidLoginThrottle extends LogicException
{
    public static function key(string $key, string $expected): self
    {
        return new self(sprintf('The login throttle setting %s is invalid: it must be %s.', $key, $expected));
    }
}
