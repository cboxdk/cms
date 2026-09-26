<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A string that is not a principal id.
 */
#[Experimental]
final class InvalidPrincipalId extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function malformed(string $value): self
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return new self(sprintf(
            'A principal id is 1 to %d visible ASCII characters, without spaces, got "%s".',
            PrincipalId::MAX_LENGTH,
            addcslashes($cut, "\0..\37\177..\377\"\\"),
        ));
    }
}
