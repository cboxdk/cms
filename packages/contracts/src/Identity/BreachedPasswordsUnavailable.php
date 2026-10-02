<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;
use Throwable;

/**
 * A BreachedPasswords implementation could not tell whether a password is known from breaches, so
 * the password is neither accepted nor refused as breached. Trying again later may help. The
 * message never holds the password or anything derived from it.
 */
#[Experimental]
final class BreachedPasswordsUnavailable extends RuntimeException
{
    public const string CODE = 'breached_passwords_unavailable';

    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Whether the password is known from breaches could not be checked: %s', $reason), 0, $previous);
    }
}
