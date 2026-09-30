<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\BecomesVisible;

/**
 * A WriteAction whose dry run shows every placement its plan makes visible (PRD 6.2 phase 6, 6.4),
 * such as entry.publish, whose release also shows the placements whose windows were open already.
 * The kernel asks it only on a dry run, with the plan as the hooks left it, and puts what it gives
 * in the DryRunReport. Like resolve() and plan(), it is pure: it looks only at the command, the
 * aggregates and the plan.
 *
 * @template TCommand of Command
 * @template TAggregates of Aggregates
 */
#[Experimental]
interface ReportsVisibility
{
    /**
     * Each placement in each locale that the plan makes visible, or visible at another time, with
     * the time it becomes visible; none when it makes nothing visible.
     *
     * @param  TCommand  $command
     * @param  TAggregates  $aggregates
     * @return list<BecomesVisible>
     */
    public function becomesVisible(Command $command, Aggregates $aggregates, Plan $plan): array;
}
