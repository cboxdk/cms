<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * The two steps of a registry fixture's write action, which reads nothing and plans nothing: the
 * registry tests read only the action's declaration.
 */
trait PlansNothing
{
    public function resolve(Command $command): NoAggregates
    {
        return new NoAggregates;
    }

    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return Plan::empty();
    }
}
