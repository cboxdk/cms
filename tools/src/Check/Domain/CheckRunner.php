<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Runs the gates in order and every step of each gate, also after a failure, so one run shows
 * every gate's state. A step that is not run is reported with its reason (GUARDRAILS 10). A step
 * that asks for its own process group gets one, and its output reader reads what it printed.
 */
final readonly class CheckRunner
{
    /**
     * Set for every command, so a Composer script run by a gate is never cut off by Composer's
     * default process timeout of 300 seconds.
     */
    public const array ENVIRONMENT = ['COMPOSER_PROCESS_TIMEOUT' => '0'];

    public function __construct(
        private ProcessRunner $processes,
        private CheckListener $listener,
    ) {}

    /**
     * @param  list<Gate>  $gates
     */
    public function run(array $gates, string $directory): CheckReport
    {
        $results = [];

        foreach ($gates as $gate) {
            $this->listener->gateStarted($gate);
            $steps = [];

            foreach ($gate->steps as $step) {
                $result = $step->notRunReason !== null
                    ? StepResult::notRun($step->name, $step->notRunReason)
                    : $this->runStep($step, $directory);
                $this->listener->stepFinished($gate, $result);
                $steps[] = $result;
            }

            $results[] = new GateResult($gate->number, $gate->title, $steps);
        }

        return new CheckReport($directory, $results);
    }

    private function runStep(Step $step, string $directory): StepResult
    {
        $outcome = $this->processes->run($step->command, $directory, self::ENVIRONMENT, ownProcessGroup: $step->ownProcessGroup);

        return $step->reader instanceof OutputReader
            ? StepResult::ran($step->name, $outcome, $step->reader->read($outcome))
            : StepResult::ran($step->name, $outcome);
    }
}
