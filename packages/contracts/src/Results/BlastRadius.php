<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The counts a dry run gives of what the write would reach (PRD 6.1, 6.2 phase 6): the number of
 * mutations in the plan and the number of distinct aggregates they change, by kind, sorted by
 * kind. It counts what the plan changes itself; what a change reaches through the durable graph,
 * such as the live fragments of a listing (PRD 9.5), is not part of it.
 */
#[Experimental]
final readonly class BlastRadius
{
    /** @var list<AggregateCount> */
    public array $aggregates;

    /**
     * @param  list<AggregateCount>  $aggregates  each kind once
     */
    public function __construct(
        public int $mutations,
        array $aggregates,
    ) {
        if ($mutations < 0) {
            throw InvalidWriteResult::count('mutation', $mutations);
        }

        $byKind = [];

        foreach ($aggregates as $count) {
            if (isset($byKind[$count->kind])) {
                throw InvalidWriteResult::repeatedKind($count->kind);
            }

            $byKind[$count->kind] = $count;
        }

        ksort($byKind, SORT_STRING);
        $this->aggregates = array_values($byKind);
    }

    /**
     * The number of aggregates of the kind the write would change, 0 when it changes none.
     */
    public function of(string $kind): int
    {
        return array_find($this->aggregates, static fn (AggregateCount $count): bool => $count->kind === $kind)->count ?? 0;
    }

    /**
     * The number of distinct aggregates the write would change, of every kind.
     */
    public function total(): int
    {
        return array_sum(array_map(static fn (AggregateCount $count): int => $count->count, $this->aggregates));
    }
}
