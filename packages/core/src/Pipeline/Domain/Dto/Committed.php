<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use InvalidArgumentException;

/**
 * The changeset was committed, now or by the first call with the same idempotency key: the receipt
 * of this call, committed or committed_wait_timeout.
 */
#[Internal]
final readonly class Committed implements CommitOutcome
{
    public function __construct(public Receipt $receipt)
    {
        if (! $receipt->isCommitted()) {
            throw new InvalidArgumentException(sprintf('A commit answers with a committed receipt, got %s.', $receipt->outcome->value));
        }
    }
}
