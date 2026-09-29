<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Every aggregate a write read, each with the version it read it at (PRD 6.2). An aggregate
 * appears at most once. The list is sorted by aggregate key, so two sets of the same reads are
 * equal whatever order they were given in, and the kernel locks them in one order.
 */
#[Experimental]
final readonly class ReadVersions
{
    /** @var list<ReadVersion> */
    public array $reads;

    public function __construct(ReadVersion ...$reads)
    {
        $byKey = [];

        foreach ($reads as $read) {
            $key = $read->aggregate->aggregateKey();

            if (isset($byKey[$key])) {
                throw InvalidPipelineValue::duplicateRead($key);
            }

            $byKey[$key] = $read;
        }

        ksort($byKey, SORT_STRING);

        $this->reads = array_values($byKey);
    }

    /**
     * The read of the aggregate, or null when the write did not read it.
     */
    public function of(AggregateRef $aggregate): ?ReadVersion
    {
        $key = $aggregate->aggregateKey();

        foreach ($this->reads as $read) {
            if ($read->aggregate->aggregateKey() === $key) {
                return $read;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->reads === [];
    }
}
