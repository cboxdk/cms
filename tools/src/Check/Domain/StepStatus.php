<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The outcome of one step of a gate, and of a gate as a whole. Elsewhere is the outcome of a step
 * that another part of the same CI run runs, so no gate is reported as not run for want of a part:
 * the gates part reports the sharded suites as run in the shard jobs, and a shard job reports the
 * other gates as run in the gates job (ShardPlan, ShardVerdict, GUARDRAILS 10).
 */
enum StepStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotRun = 'not run';
    case Elsewhere = 'elsewhere';
}
