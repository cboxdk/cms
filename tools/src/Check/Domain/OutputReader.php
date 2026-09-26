<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Reads the output of a step's command after it ran. A step whose command prints a report, such
 * as composer audit, uses one to list what the report found and to fail on what the exit code
 * alone does not show.
 */
interface OutputReader
{
    public function read(ProcessOutcome $outcome): OutputReading;
}
