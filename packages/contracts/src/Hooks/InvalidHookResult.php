<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A hook built an answer the kernel cannot use: a denial or an error without a message, or a view
 * of a plan whose command version is below 1.
 */
#[Experimental]
final class InvalidHookResult extends InvalidArgumentException
{
    public static function emptyReason(): self
    {
        return new self('A hook that denies a command gives its reason in plain language.');
    }

    public static function emptyMessage(): self
    {
        return new self('A hook error says in plain language what is wrong.');
    }

    public static function version(int $version): self
    {
        return new self(sprintf('A command version starts at 1, got %d.', $version));
    }
}
