<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A class the subscribers registry names that the container does not build as a Subscriber: the
 * registry is older than the code. cms:build writes it again.
 */
#[Internal]
final class UnusableSubscriber extends LogicException
{
    public static function notASubscriber(string $class): self
    {
        return new self(sprintf('The subscribers registry names %s, which the container does not build as a Cbox\Cms\Contracts\Subscribers\Subscriber. Run cms:build.', $class));
    }
}
