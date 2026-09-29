<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use InvalidArgumentException;

/**
 * The changeset was written in the command transaction: the receipt of this call, committed or
 * committed_wait_timeout, and the changeset's id, which completes the call's idempotency claim.
 */
#[Internal]
final readonly class Committed implements CommitOutcome
{
    public ChangesetId $changesetId;

    public function __construct(public Receipt $receipt)
    {
        $changesetId = $receipt->changesetId;

        // A receipt carries a changeset exactly when it is committed or committed_wait_timeout.
        if (! $changesetId instanceof ChangesetId) {
            throw new InvalidArgumentException(sprintf('A commit answers with a committed receipt, got %s.', $receipt->outcome->value));
        }

        $this->changesetId = $changesetId;
    }
}
