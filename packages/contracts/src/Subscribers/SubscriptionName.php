<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Subscribers;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a subscription, such as "fragments.invalidate" (PRD 7.6). The event log keeps the
 * subscription's cursor per stream and its parked aggregates under it, so the name stays when the
 * class is renamed or moved. It is one or more snake_case segments separated by dots, so an addon
 * can use its own prefix, and at most MAX_LENGTH characters, as the event_cursors table checks.
 */
#[Experimental]
final readonly class SubscriptionName
{
    public const int MAX_LENGTH = 63;

    public const string PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidSubscriptionName::of($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
