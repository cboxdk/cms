<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * One aggregate a dry run would change (PRD 6.2 phase 6): the version it was read at, or null when
 * the write creates it, the version the commit would give it, one higher or the first, and how
 * many of the plan's mutations change it.
 */
#[Experimental]
final readonly class AggregateChange
{
    public AggregateVersion $after;

    public function __construct(
        public AggregateRef $aggregate,
        public ?AggregateVersion $before,
        public int $mutations,
    ) {
        if ($mutations < 1) {
            throw InvalidWriteResult::changeWithoutMutations($aggregate->aggregateKey());
        }

        $this->after = $before instanceof AggregateVersion ? $before->next() : AggregateVersion::first();
    }

    /**
     * Whether the write creates the aggregate.
     */
    public function creates(): bool
    {
        return ! $this->before instanceof AggregateVersion;
    }
}
