<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * What a dry run gives instead of a commit (PRD 6.1, 6.2 phase 6): the plan that would have been
 * committed, its blast radius as counts, and its diff: one AggregateChange per aggregate the plan
 * changes, with the version it was read at and the version the commit would give it, sorted by
 * aggregate key.
 */
#[Experimental]
final readonly class DryRunReport
{
    /**
     * @param  list<AggregateChange>  $diff
     */
    private function __construct(
        public Plan $plan,
        public BlastRadius $blastRadius,
        public array $diff,
    ) {}

    /**
     * The report of a plan made from the reads. Every aggregate a mutation changes must be one
     * of the reads, as the kernel requires before it commits.
     *
     * @throws InvalidWriteResult when a mutation changes an aggregate that was not read
     */
    public static function of(Plan $plan, ReadVersions $reads): self
    {
        $mutations = $plan->mutations();
        $changed = [];
        $counts = [];

        foreach ($mutations as $mutation) {
            $key = $mutation->aggregate()->aggregateKey();
            $read = $reads->of($mutation->aggregate());

            if (! $read instanceof ReadVersion) {
                throw InvalidWriteResult::unreadAggregate($key);
            }

            $changed[$key] = [$read, ($changed[$key][1] ?? 0) + 1];
        }

        ksort($changed, SORT_STRING);
        $diff = [];

        foreach ($changed as $key => [$read, $count]) {
            $diff[] = new AggregateChange($read->aggregate, $read->version, $count);
            $kind = strstr($key, ':', true);
            $kind = $kind === false ? $key : $kind;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        $aggregates = [];

        foreach ($counts as $kind => $count) {
            $aggregates[] = new AggregateCount((string) $kind, $count);
        }

        return new self($plan, new BlastRadius(count($mutations), $aggregates), $diff);
    }
}
