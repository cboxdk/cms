<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * ReceiptStore::store() was called without an open transaction on the caller's connection. The
 * receipt is written in the command transaction (PRD 6.2 phase 7), so this is a bug in the caller,
 * not a result. Nothing was stored.
 */
#[Experimental]
final class TransactionRequired extends LogicException
{
    public static function forStore(): self
    {
        return new self(
            'ReceiptStore::store() runs inside the caller\'s command transaction, and the connection has none open. The receipt commits and rolls back with its changeset, and the transaction holds the lock that keeps one receipt per changeset. Nothing was stored.',
        );
    }
}
