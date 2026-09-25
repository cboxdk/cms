<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use RuntimeException;

/**
 * The receipt store already holds a receipt for the changeset. A changeset has one receipt.
 */
#[Experimental]
final class DuplicateReceipt extends RuntimeException
{
    public static function forChangeset(ChangesetId $changesetId): self
    {
        return new self(sprintf(
            'The receipt store already holds a receipt for changeset %s. A changeset has one receipt; mark its projections instead.',
            $changesetId->toString(),
        ));
    }
}
