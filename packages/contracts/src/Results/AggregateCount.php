<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How many aggregates of one kind a dry run would change (PRD 6.2 phase 6), such as 1 entry and
 * 2 placements. The kind is the part of the aggregate's key before the first colon.
 */
#[Experimental]
final readonly class AggregateCount
{
    public function __construct(
        public string $kind,
        public int $count,
    ) {
        if ($kind === '' || $count < 1) {
            throw InvalidWriteResult::count($kind, $count);
        }
    }
}
