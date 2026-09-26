<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Domain;

use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;

/**
 * Whether the right gate caught a planted violation: the plant's step failed, its output
 * contains every marker, and it names the planted file at a path inside the worktree.
 */
final readonly class PlantVerdict
{
    /**
     * @param  list<string>  $problems  why the plant does not count as caught; empty when it does
     */
    private function __construct(
        public Plant $plant,
        public ?string $reportedPath,
        public array $problems,
    ) {}

    public static function of(Plant $plant, CheckReport $report, string $worktree): self
    {
        $step = $report->gate($plant->gate)?->step($plant->step);

        if (! $step instanceof StepResult) {
            return new self($plant, null, ["the report has no step {$plant->step} in gate {$plant->gate}"]);
        }

        $problems = [];

        if ($step->status !== StepStatus::Fail) {
            $problems[] = "{$plant->step} did not fail, its status is {$step->status->value}";
        }

        $output = ReportedPaths::normalize($step->output);

        foreach ($plant->markers as $marker) {
            if (! str_contains($output, $marker)) {
                $problems[] = "the output of {$plant->step} does not contain '{$marker}'";
            }
        }

        $paths = ReportedPaths::search($step->output, $plant->path, $worktree);

        if ($paths->found === []) {
            $problems[] = "the output of {$plant->step} does not name {$plant->path} at a path inside the worktree";
        }

        foreach ($paths->outside as $outside) {
            $problems[] = "the output of {$plant->step} names {$outside}, outside the worktree";
        }

        return new self($plant, $paths->found[0] ?? null, $problems);
    }

    public function caught(): bool
    {
        return $this->problems === [];
    }
}
