<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * What one step did: its status, the command it ran, the exit code and the combined output of
 * that command, or why it did not run, and the notes its output reader found. A step decided
 * without a command has no command, no exit code and no output: it failed with a reason, or passed
 * with a note.
 */
final readonly class StepResult
{
    /**
     * @param  list<string>  $notes
     * @param  list<string>  $command  the command the step ran, empty when it ran none
     */
    private function __construct(
        public string $step,
        public StepStatus $status,
        public ?int $exitCode,
        public string $output,
        public float $seconds,
        public ?string $reason,
        public array $notes = [],
        public array $command = [],
    ) {}

    /**
     * A step that ran fails when its command did not succeed or its output reader found a failure.
     *
     * @param  list<string>  $command  the command the step ran
     */
    public static function ran(string $step, ProcessOutcome $outcome, OutputReading $reading = new OutputReading, array $command = []): self
    {
        return new self(
            $step,
            $outcome->succeeded() && $reading->failure === null ? StepStatus::Pass : StepStatus::Fail,
            $outcome->exitCode,
            $outcome->output,
            $outcome->seconds,
            $outcome->timedOut ? 'timed out' : $reading->failure,
            $reading->notes,
            $command,
        );
    }

    public static function notRun(string $step, string $reason): self
    {
        return new self($step, StepStatus::NotRun, null, '', 0.0, $reason);
    }

    /**
     * The result of a step decided without a command: a pass lists its note, a fail its reason.
     */
    public static function decided(string $step, StepStatus $status, string $decision): self
    {
        return match ($status) {
            StepStatus::Pass => new self($step, StepStatus::Pass, null, '', 0.0, null, new OutputReading([$decision])->notes),
            StepStatus::Fail => new self($step, StepStatus::Fail, null, '', 0.0, $decision),
            StepStatus::NotRun => throw new InvalidArgumentException("Step {$step} is not run; it is not decided."),
        };
    }

    /**
     * Rebuilds a result from a report. The status must fit the rest: a step that did not run has
     * a reason and no exit code, and a step that ran has an exit code that matches its status,
     * unless a reason says why a step with exit code 0 failed. A step decided without a command
     * has no exit code: it passed with a note and no reason, or failed with a reason.
     *
     * @param  list<string>  $notes
     * @param  list<string>  $command
     */
    public static function restore(string $step, StepStatus $status, ?int $exitCode, string $output, float $seconds, ?string $reason, array $notes = [], array $command = []): self
    {
        $consistent = match ($status) {
            StepStatus::NotRun => $exitCode === null && $reason !== null && $notes === [],
            StepStatus::Pass => $exitCode === 0 || $exitCode === null && $reason === null && $notes !== [],
            StepStatus::Fail => $exitCode !== 0 || $reason !== null,
        };

        if (! $consistent) {
            throw new InvalidArgumentException("The result of step {$step} is inconsistent: status {$status->value} with exit code ".($exitCode ?? 'none').'.');
        }

        return new self($step, $status, $exitCode, $output, $seconds, $reason, new OutputReading($notes)->notes, $command);
    }
}
