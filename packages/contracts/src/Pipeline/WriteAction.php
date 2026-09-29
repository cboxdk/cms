<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * One write use case (GUARDRAILS 2.1, PRD 6.2): a final readonly class the kernel calls in two
 * steps. Both are pure: neither writes, commits, calls another write action or depends on
 * anything but its arguments and the read ports it was given in its constructor.
 *
 * - resolve() reads the aggregates the command touches and returns them with the versions they
 *   were read at (phase 1).
 * - plan() computes the Plan, the typed mutations, from the command and those aggregates
 *   (phase 3). It may compose the plans of other commands' planners.
 *
 * The kernel owns the rest: authorization, hooks, validation, the dry-run exit and the commit
 * with a version check of every aggregate read. The surfaces the action is exposed on are
 * declared with #[Action].
 *
 * @template TCommand of Command
 * @template TAggregates of Aggregates
 */
#[Experimental]
interface WriteAction
{
    /**
     * @param  TCommand  $command
     * @return TAggregates
     */
    public function resolve(Command $command): Aggregates;

    /**
     * @param  TCommand  $command
     * @param  TAggregates  $aggregates
     */
    public function plan(Command $command, Aggregates $aggregates): Plan;
}
