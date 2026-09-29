<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use InvalidArgumentException;

/**
 * Parses the arguments and options of the cms:events:* commands into their DTOs' values.
 */
#[Internal]
final readonly class SubscriptionArguments
{
    /**
     * The lane a runner runs. The runner implements the critical lane's behaviour, with retries and
     * parking (PRD 7.6 to 7.8); the other lanes' own behaviour, such as the external lane's circuit
     * breaker, is not built, so they are refused.
     *
     * @throws InvalidArgumentException when it is not the value of a lane the runner runs
     */
    public static function lane(mixed $value): Lane
    {
        $lane = is_string($value) ? Lane::tryFrom($value) : null;

        return match ($lane) {
            Lane::Critical => $lane,
            null => throw new InvalidArgumentException(sprintf('--lane must be one of %s.', implode(', ', array_map(static fn (Lane $lane): string => $lane->value, Lane::cases())))),
            default => throw new InvalidArgumentException(sprintf('The runner runs the critical lane; the %s lane is not built yet.', $lane->value)),
        };
    }

    /**
     * @throws InvalidArgumentException when it is not a subscription name
     */
    public static function subscription(mixed $value): SubscriptionName
    {
        return new SubscriptionName(is_string($value) ? $value : '');
    }

    /**
     * An aggregate written "<type>:<id>", such as entry:0196...
     *
     * @throws InvalidArgumentException when it is not in that form
     */
    public static function aggregate(mixed $value): AggregateKey
    {
        try {
            return AggregateKey::fromString(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('The aggregate is written <type>:<id>, such as entry:01960000-0000-7000-8000-000000000001.');
        }
    }
}
