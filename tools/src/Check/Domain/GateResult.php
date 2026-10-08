<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * The results of one gate's steps. A gate fails when one step fails, passes when at least one
 * step passed and none failed, runs elsewhere when another part of the run runs every step of it
 * (StepStatus::Elsewhere), and is not run when none of its steps ran at all.
 */
final readonly class GateResult
{
    /**
     * @param  list<StepResult>  $steps
     */
    public function __construct(
        public int $number,
        public string $title,
        public array $steps,
    ) {
        if ($steps === []) {
            throw new InvalidArgumentException("The result of gate {$number} has no steps.");
        }
    }

    public function status(): StepStatus
    {
        $statuses = array_map(static fn (StepResult $step): StepStatus => $step->status, $this->steps);

        return match (true) {
            in_array(StepStatus::Fail, $statuses, true) => StepStatus::Fail,
            in_array(StepStatus::Pass, $statuses, true) => StepStatus::Pass,
            in_array(StepStatus::Elsewhere, $statuses, true) => StepStatus::Elsewhere,
            default => StepStatus::NotRun,
        };
    }

    public function step(string $name): ?StepResult
    {
        foreach ($this->steps as $step) {
            if ($step->step === $name) {
                return $step;
            }
        }

        return null;
    }

    /**
     * A reason when every step was not run, or run in another part, for the same reason, as for
     * the gates outside the profile and the gates a shard job leaves to the gates job.
     */
    public function notRunReason(): ?string
    {
        $reasons = array_unique(array_map(static fn (StepResult $step): string => (string) $step->reason, $this->steps));
        $status = $this->status();

        return ($status === StepStatus::NotRun || $status === StepStatus::Elsewhere) && count($reasons) === 1 ? $reasons[0] : null;
    }
}
