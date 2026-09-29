<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use RuntimeException;

/**
 * A release of a parked aggregate that cannot be made (PRD 7.8): no registered subscriber has the
 * subscription (CODE_UNKNOWN), or the aggregate is not parked for it (CODE_NOT_PARKED).
 */
#[Experimental]
final class ReleaseRefused extends RuntimeException
{
    public const string CODE_UNKNOWN = 'subscription_unknown';

    public const string CODE_NOT_PARKED = 'subscription_not_parked';

    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function unknown(SubscriptionName $subscription): self
    {
        return new self(sprintf('No registered subscriber has the subscription "%s". Check the name, or run cms:build.', $subscription->value), self::CODE_UNKNOWN);
    }

    public static function notParked(SubscriptionName $subscription, AggregateKey $aggregate): self
    {
        return new self(sprintf('The aggregate %s is not parked for the subscription "%s".', $aggregate->toString(), $subscription->value), self::CODE_NOT_PARKED);
    }
}
