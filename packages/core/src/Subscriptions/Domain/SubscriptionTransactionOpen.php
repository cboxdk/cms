<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A runner's batch was asked to begin on a connection that already has a transaction open. A batch
 * never nests: its cursor must commit with the subscribers' writes in a transaction of its own, and
 * savepoints are forbidden (PRD 4.2).
 */
#[Internal]
final class SubscriptionTransactionOpen extends LogicException
{
    public static function onConnection(string $connection): self
    {
        return new self(sprintf('A subscription batch begins its own transaction, and the connection "%s" already has one open.', $connection));
    }
}
