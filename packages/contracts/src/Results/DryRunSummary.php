<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a dry run reports to its caller (PRD 6.1, 6.2 phase 6), without the plan: the blast
 * radius, the version change of every aggregate the commit would make, sorted by aggregate key,
 * and every placement the write makes visible, sorted by placement and locale. It is the
 * DryRunReport as a surface shows it: the plan's mutations carry typed ids and field values that
 * no caller needs to see, so the summary carries the counts and the changes alone. Its JSON form is
 * dry-run-summary.v1.json, written and read only by the generated DryRunSummaryCodecV1
 * (GUARDRAILS 2.2).
 */
#[Experimental]
final readonly class DryRunSummary
{
    /** @var list<VersionChange> */
    public array $changes;

    /**
     * @param  list<VersionChange>  $changes  each aggregate once
     * @param  list<BecomesVisible>  $becomesVisible
     */
    public function __construct(
        public BlastRadius $blastRadius,
        array $changes,
        public array $becomesVisible,
    ) {
        $byKey = [];

        foreach ($changes as $change) {
            if (isset($byKey[$change->aggregate])) {
                throw InvalidWriteResult::repeatedAggregate($change->aggregate);
            }

            $byKey[$change->aggregate] = $change;
        }

        ksort($byKey, SORT_STRING);
        $this->changes = array_values($byKey);
    }

    public static function of(DryRunReport $report): self
    {
        return new self($report->blastRadius, array_map(VersionChange::of(...), $report->diff), $report->visible);
    }
}
