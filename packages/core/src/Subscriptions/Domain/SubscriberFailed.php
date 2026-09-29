<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;
use Throwable;

/**
 * A subscriber threw while the runner handed it the unit at $index of a batch, an event or a
 * released aggregate. The runner throws it out of the batch's transaction, which rolls back what
 * the batch wrote, and then tries again: the units before $index at once, the failed one after its
 * backoff (PRD 7.7).
 */
#[Internal]
final class SubscriberFailed extends RuntimeException
{
    public function __construct(
        public readonly string $unit,
        public readonly int $index,
        Throwable $cause,
    ) {
        parent::__construct(sprintf('The subscriber failed on %s: %s', $unit, $cause->getMessage()), 0, $cause);
    }
}
