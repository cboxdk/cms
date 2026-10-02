<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * The final verdict of a CI run whose mutation on changed files ran in shards: the last job of
 * .github/workflows/ci.yml, and the last part of bin/ci's containerized run. It passes only when
 * the gates passed, every shard job passed, every shard of the plan reported exactly once with the
 * plan's sources, and every changed class reaches the minimum score over all its mutations in all
 * shards, one verdict per class. A shard that did not report, a report of a shard the plan does
 * not have, and a class no shard counted each fail it, so nothing passes for want of a report.
 */
final readonly class MutationVerdict
{
    /**
     * @param  list<ClassTally>  $classes  every class the shards counted, sorted by path
     * @param  list<string>  $failures
     */
    private function __construct(
        public array $classes,
        public array $failures,
    ) {}

    /**
     * @param  list<MutationShardReport>  $reports
     */
    public static function judge(MutationPlan $plan, array $reports, JobResult $gates, JobResult $shards, int $minScore = MutationSteps::MIN_SCORE): self
    {
        if ($minScore < 0 || $minScore > 100) {
            throw new InvalidArgumentException("A minimum score is a percentage, not {$minScore}.");
        }

        $failures = [];

        if ($plan->failure !== null) {
            $failures[] = "the plan has no base of the change: {$plan->failure}";
        }

        if ($gates !== JobResult::Success) {
            $failures[] = "the gates ended {$gates->value}";
        }

        if ($shards !== JobResult::Success) {
            $failures[] = "the mutation shards ended {$shards->value}";
        }

        $byIndex = [];

        foreach ($reports as $report) {
            if ($report->count !== $plan->count() || $report->index > $plan->count()) {
                $failures[] = "a report of shard {$report->index} of {$report->count}, but the plan has {$plan->count()} shards";

                continue;
            }

            if (isset($byIndex[$report->index])) {
                $failures[] = "shard {$report->index} of {$report->count} reported twice";

                continue;
            }

            $byIndex[$report->index] = $report;
        }

        $classes = [];

        foreach ($plan->shards as $shard) {
            $report = $byIndex[$shard->index] ?? null;

            if (! $report instanceof MutationShardReport) {
                $failures[] = "{$shard->label()} did not report";

                continue;
            }

            if (! $report->shard($shard)) {
                $failures[] = "{$shard->label()} reported other sources than the plan gave it";

                continue;
            }

            if (! $report->passed) {
                $failures[] = "{$shard->label()} failed";
            }

            foreach ($shard->sources as $source) {
                $tally = $report->tally($source->path);

                if (! $tally instanceof ClassTally) {
                    $failures[] = "{$source->name} has no mutation count in {$shard->label()}";

                    continue;
                }

                $classes[] = $tally;

                if (! $tally->reaches($minScore)) {
                    $failures[] = sprintf('%s is below %d%% over its mutations: %s%%', $tally->name, $minScore, number_format($tally->count->score(), 2));
                }
            }
        }

        usort($classes, static fn (ClassTally $a, ClassTally $b): int => strcmp($a->path, $b->path));

        return new self($classes, $failures);
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * The verdict as lines: each class with its score, the total, and each failure.
     *
     * @return list<string>
     */
    public function lines(int $minScore = MutationSteps::MIN_SCORE): array
    {
        $mutations = array_sum(array_map(static fn (ClassTally $class): int => $class->count->mutations, $this->classes));
        $caught = array_sum(array_map(static fn (ClassTally $class): int => $class->count->caught, $this->classes));
        $lines = array_map(static fn (ClassTally $class): string => ($class->reaches($minScore) ? 'pass  ' : 'fail  ').$class->describe(), $this->classes);
        $lines[] = sprintf('%d classes, %d of %d mutations caught, minimum %d%% for each class', count($this->classes), $caught, $mutations, $minScore);

        foreach ($this->failures as $failure) {
            $lines[] = 'failed: '.$failure;
        }

        $lines[] = $this->passed() ? 'verdict: pass' : 'verdict: fail';

        return $lines;
    }
}
