<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use LogicException;

/**
 * ReceiptStore::store() was given a receipt whose position is not the commit position of the
 * caller's transaction (PRD 8.4). The receipt is stored in the command transaction, so its position
 * is that transaction's xid8, the one the changeset and its events carry; any other value would
 * make a read believe it saw a changeset it did not. This is a bug in the caller, not a result.
 * Nothing was stored.
 */
#[Experimental]
final class ForeignPosition extends LogicException
{
    public static function forReceipt(ChangesetId $changesetId, CommitPosition $given, CommitPosition $transaction): self
    {
        return new self(sprintf(
            'The receipt of changeset %s has the position %s, and the transaction storing it is at %s. A receipt carries the commit position of its own command transaction. Nothing was stored.',
            $changesetId->toString(),
            $given->value,
            $transaction->value,
        ));
    }
}
