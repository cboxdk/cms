<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Cbox\Cms\Contracts\Events\Event;
use InvalidArgumentException;

/**
 * #[Subscription] lists an event class that does not exist or does not implement Event. cms:build
 * reports it as registry_unknown_event.
 */
#[Experimental]
final class UnknownEvent extends InvalidArgumentException
{
    public static function missing(string $class): self
    {
        return new self(sprintf(
            '#[Subscription] lists the event class "%s", which does not exist. List the classes of the events, such as VariantReleased::class.',
            $class,
        ));
    }

    public static function notAnEvent(string $class): self
    {
        return new self(sprintf(
            '#[Subscription] lists the class "%s", which does not implement %s. List the classes of the events, such as VariantReleased::class.',
            $class,
            Event::class,
        ));
    }
}
