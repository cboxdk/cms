<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * A store was called inside a transaction at an isolation level it cannot keep its promise under.
 * It is a bug in the caller, not a result: the command transaction runs at READ COMMITTED.
 */
#[Experimental]
final class UnsupportedIsolation extends LogicException
{
    /**
     * The receipt store keeps one receipt per changeset by waiting for another store of the
     * changeset and then looking for its receipt, which only READ COMMITTED lets it see: under
     * REPEATABLE READ or SERIALIZABLE the snapshot is older than the wait.
     */
    public static function receiptStore(string $level): self
    {
        return new self(sprintf(
            'ReceiptStore::store() needs the caller\'s transaction at READ COMMITTED, and it is %s. After waiting for another store of the changeset, it must see the receipt that store committed.',
            strtoupper($level),
        ));
    }
}
