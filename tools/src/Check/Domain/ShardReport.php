<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * What one shard of the sharded suites did, as the verdict over the parts of a CI run reads it
 * (ShardVerdict): which shard of how many it was, whether its `composer check` passed, and which
 * sharded steps it actually ran. The steps are how the verdict sees that no sharded suite was
 * left out of a shard: a shard that ran fewer than the plan's steps fails the verdict.
 */
final readonly class ShardReport
{
    /**
     * @param  list<string>  $steps  the sharded steps the shard ran, in the plan's order
     */
    public function __construct(
        public int $index,
        public int $count,
        public bool $passed,
        public array $steps,
    ) {
        if ($count < 1 || $index < 1 || $index > $count) {
            throw new InvalidArgumentException("Shard {$index} of {$count} is not a shard: it is numbered from 1 to the number of shards.");
        }
    }

    public function label(): string
    {
        return "shard {$this->index} of {$this->count}";
    }

    /**
     * Whether the shard ran exactly the steps the plan shards.
     */
    public function ranThePlan(): bool
    {
        return $this->steps === ShardPlan::names();
    }
}
