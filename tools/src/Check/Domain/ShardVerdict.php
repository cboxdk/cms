<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\JobResult;

/**
 * The final verdict of a CI run whose sharded suites ran in shards: the last job of
 * .github/workflows/ci.yml, and the last part of bin/ci's containerized run. It passes only when
 * the gates part passed, the shard jobs passed, and every shard of the declared plan (ShardPlan)
 * reported exactly once with every sharded step run. A shard that did not report, a report of a
 * shard the plan does not have, a shard that reported twice and a shard that ran fewer steps than
 * the plan each fail it, so nothing passes for want of a report and no sharded suite is dropped
 * (GUARDRAILS 10, GUARDRAILS 7.3).
 */
final readonly class ShardVerdict
{
    /**
     * @param  list<ShardReport>  $reports  the shards that reported, in the order they were read
     * @param  list<string>  $failures
     */
    private function __construct(
        public array $reports,
        public array $failures,
    ) {}

    /**
     * @param  list<ShardReport>  $reports
     */
    public static function judge(array $reports, JobResult $gates, JobResult $shards): self
    {
        $failures = [];

        if ($gates !== JobResult::Success) {
            $failures[] = "the gates ended {$gates->value}";
        }

        if ($shards !== JobResult::Success) {
            $failures[] = "the shard jobs ended {$shards->value}";
        }

        $byIndex = [];

        foreach ($reports as $report) {
            if ($report->count !== ShardPlan::SHARDS) {
                $failures[] = "a report of {$report->label()}, but the plan has ".ShardPlan::SHARDS.' shards';

                continue;
            }

            if (isset($byIndex[$report->index])) {
                $failures[] = "{$report->label()} reported twice";

                continue;
            }

            $byIndex[$report->index] = $report;
        }

        $accepted = [];

        foreach (ShardPlan::indexes() as $index) {
            $report = $byIndex[$index] ?? null;

            if (! $report instanceof ShardReport) {
                $failures[] = sprintf('shard %d of %d did not report', $index, ShardPlan::SHARDS);

                continue;
            }

            $accepted[] = $report;

            if (! $report->passed) {
                $failures[] = "{$report->label()} failed";
            }

            if (! $report->ranThePlan()) {
                $failures[] = sprintf(
                    '%s ran %s, not %s',
                    $report->label(),
                    $report->steps === [] ? 'none of the sharded steps' : implode(' and ', $report->steps),
                    implode(' and ', ShardPlan::names()),
                );
            }
        }

        return new self($accepted, $failures);
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * The verdict as lines: each shard with what it ran, the plan, and each failure.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = array_map(
            static fn (ShardReport $report): string => sprintf(
                '%s  %s ran %s',
                $report->passed && $report->ranThePlan() ? 'pass' : 'fail',
                $report->label(),
                $report->steps === [] ? 'none of the sharded steps' : implode(', ', $report->steps),
            ),
            $this->reports,
        );
        $lines[] = sprintf('%d of %d shards reported, each running %s', count($this->reports), ShardPlan::SHARDS, implode(' and ', ShardPlan::names()));

        foreach ($this->failures as $failure) {
            $lines[] = 'failed: '.$failure;
        }

        $lines[] = $this->passed() ? 'verdict: pass' : 'verdict: fail';

        return $lines;
    }
}
