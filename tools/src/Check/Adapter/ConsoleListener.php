<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Adapter;

use Cbox\Cms\Tooling\Check\Domain\CheckListener;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;

/**
 * Prints each gate and step as it finishes, and the output of a failed step right below it.
 * Gates outside the profile show only in the summary. Brief output leaves the failed output out; the report file still has it.
 */
final readonly class ConsoleListener implements CheckListener
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        private mixed $stream,
        private bool $brief = false,
    ) {}

    public function gateStarted(Gate $gate): void
    {
        if ($this->runs($gate)) {
            $this->write(ReportFormatter::gateHeading($gate));
        }
    }

    public function stepFinished(Gate $gate, StepResult $result): void
    {
        if (! $this->runs($gate)) {
            return;
        }

        $this->write(ReportFormatter::stepLine($result));

        if ($result->status === StepStatus::Fail && ! $this->brief) {
            $this->write(ReportFormatter::failureOutput($result));
        }
    }

    /**
     * A gate outside the profile runs nothing; it shows only in the summary.
     */
    private function runs(Gate $gate): bool
    {
        return array_filter($gate->steps, static fn (Step $step): bool => $step->runs()) !== [];
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
        fflush($this->stream);
    }
}
