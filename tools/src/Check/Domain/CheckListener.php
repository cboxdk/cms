<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Hears about each gate and step while the check runs, so the output shows progress.
 */
interface CheckListener
{
    public function gateStarted(Gate $gate): void;

    public function stepFinished(Gate $gate, StepResult $result): void;
}
