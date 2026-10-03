<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * A text is not a client's IP address (PRD 5.16): it is empty, or not an IPv4 or IPv6 address. The
 * message never repeats the text, which may be personal data.
 */
#[Internal]
final class InvalidClientAddress extends InvalidArgumentException
{
    public static function notAnAddress(): self
    {
        return new self('A client address is an IPv4 or IPv6 address.');
    }
}
