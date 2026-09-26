<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The outcome of one step of a gate, and of a gate as a whole.
 */
enum StepStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotRun = 'not run';
}
