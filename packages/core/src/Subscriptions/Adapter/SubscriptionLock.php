<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use LogicException;

/**
 * The advisory lock key a subscription's batches hold in Postgres, so two runners of a lane never
 * handle one subscription at the same time (PRD 7.6): the first 64 bits, as a signed bigint, of the
 * SHA-256 of a versioned JSON list of a fixed prefix and the subscription's name. The prefix
 * differs from those of the receipt and idempotency locks, so two kinds of lock share a key only by
 * a 64-bit collision, which only makes the two wait for each other. The encoding is fixed: a new one
 * needs a new prefix.
 */
#[Internal]
final readonly class SubscriptionLock
{
    public const string VERSION = 'cbox_cms.subscription.v1';

    public static function of(SubscriptionName $subscription): int
    {
        $digest = hash('sha256', json_encode([self::VERSION, $subscription->value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $unpacked = unpack('J', (string) hex2bin(substr($digest, 0, 16)));
        $key = is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        return is_int($key) ? $key : throw new LogicException('Could not read the advisory lock key from the subscription digest.');
    }
}
