<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A string that is not a command name.
 */
#[Experimental]
final class InvalidCommandName extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function malformed(string $value): self
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return new self(sprintf(
            'A command name is dot-separated snake_case segments, for example "entry.release", got "%s".',
            addcslashes($cut, "\0..\37\177..\377\"\\"),
        ));
    }
}
