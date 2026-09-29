<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * An operation was run inside an open transaction. Each chunk commits on its own, so a transaction
 * around the run would hold every chunk's writes and locks until the end (GUARDRAILS 4.1, 4.2).
 * Nothing was started or run.
 */
#[Experimental]
final class OperationInsideTransaction extends LogicException
{
    public static function level(int $level): self
    {
        return new self(sprintf(
            'An operation runs its chunks one transaction at a time and cannot run inside an open transaction (transaction level %d). Run it from a console command or a queued job, outside any transaction.',
            $level,
        ));
    }
}
