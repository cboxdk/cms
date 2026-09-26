<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * What one step did: its status, the exit code and the combined output of its command, or why
 * it did not run.
 */
final readonly class StepResult
{
    private function __construct(
        public string $step,
        public StepStatus $status,
        public ?int $exitCode,
        public string $output,
        public float $seconds,
        public ?string $reason,
    ) {}

    public static function ran(string $step, ProcessOutcome $outcome): self
    {
        return new self(
            $step,
            $outcome->succeeded() ? StepStatus::Pass : StepStatus::Fail,
            $outcome->exitCode,
            $outcome->output,
            $outcome->seconds,
            $outcome->timedOut ? 'timed out' : null,
        );
    }

    public static function notRun(string $step, string $reason): self
    {
        return new self($step, StepStatus::NotRun, null, '', 0.0, $reason);
    }

    /**
     * Rebuilds a result from a report. The status must fit the rest: a step that did not run has
     * a reason and no exit code, and a step that ran has an exit code that matches its status.
     */
    public static function restore(string $step, StepStatus $status, ?int $exitCode, string $output, float $seconds, ?string $reason): self
    {
        $consistent = match ($status) {
            StepStatus::NotRun => $exitCode === null && $reason !== null,
            StepStatus::Pass => $exitCode === 0,
            StepStatus::Fail => $exitCode !== 0,
        };

        if (! $consistent) {
            throw new InvalidArgumentException("The result of step {$step} is inconsistent: status {$status->value} with exit code ".($exitCode ?? 'none').'.');
        }

        return new self($step, $status, $exitCode, $output, $seconds, $reason);
    }
}
