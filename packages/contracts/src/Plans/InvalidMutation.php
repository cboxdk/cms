<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use InvalidArgumentException;

/**
 * A mutation that breaks its invariants.
 */
#[Experimental]
final class InvalidMutation extends InvalidArgumentException
{
    public static function headDidNotMove(RevisionNumber $revision): self
    {
        return new self(sprintf('A head move goes to another revision, but both are revision %d.', $revision->value));
    }
}
