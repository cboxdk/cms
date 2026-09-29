<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use RuntimeException;

/**
 * A replay found no receipt for the changeset its idempotency record names (PRD 6.1). The record
 * expires with the Standard receipt of its changeset, and the receipt is stored in the transaction
 * that completes the record, so a live record always has a receipt; this means the two stores
 * disagree. Nothing was run, and the command transaction is rolled back.
 */
#[Internal]
final class MissingReplayReceipt extends RuntimeException
{
    public static function of(CommandName $command, ChangesetId $changesetId): self
    {
        return new self(sprintf('The idempotency record of this %s call names the changeset %s, and the receipt store has no receipt for it. The idempotency store and the receipt store disagree.', $command->value, $changesetId->toString()));
    }
}
