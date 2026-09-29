<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Subscribers;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A subscription name that is not one or more dot-separated snake_case segments of at most
 * SubscriptionName::MAX_LENGTH characters.
 */
#[Experimental]
final class InvalidSubscriptionName extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function of(string $value): self
    {
        return new self(sprintf(
            'The subscription name "%s" must be dot-separated snake_case segments of at most %d characters, for example "fragments.invalidate".',
            strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value,
            SubscriptionName::MAX_LENGTH,
        ));
    }
}
