<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Cbox\Cms\Contracts\Subscribers\Lane;
use InvalidArgumentException;

/**
 * #[Subscription] names a lane that is not a case of Lane, such as the string 'critical' instead of
 * Lane::Critical. cms:build reports it as registry_unknown_lane.
 */
#[Experimental]
final class UnknownLane extends InvalidArgumentException
{
    public static function named(string $lane): self
    {
        return new self(sprintf(
            '#[Subscription] names the lane "%s", which is not a lane. Name a case of %s: %s.',
            $lane,
            Lane::class,
            self::cases(),
        ));
    }

    /**
     * The cases of Lane as PHP spells them, such as "Lane::Critical, Lane::Standard".
     */
    public static function cases(): string
    {
        return implode(', ', array_map(static fn (Lane $case): string => 'Lane::'.$case->name, Lane::cases()));
    }
}
