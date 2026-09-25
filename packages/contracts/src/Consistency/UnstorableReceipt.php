<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A receipt the receipt store does not take: only receipts of committed changesets are stored.
 */
#[Experimental]
final class UnstorableReceipt extends InvalidArgumentException
{
    public static function notCommitted(Outcome $outcome): self
    {
        return new self(sprintf(
            'A %s receipt is not stored: the command committed nothing. Only committed and committed_wait_timeout receipts are stored.',
            $outcome->value,
        ));
    }
}
