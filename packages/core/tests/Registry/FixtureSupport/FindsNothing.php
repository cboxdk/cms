<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The steps of a registry fixture's query action, which costs nothing and finds nothing: the
 * registry tests read only the action's declaration.
 */
trait FindsNothing
{
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(0);
    }

    public function handle(Query $query): NothingFound
    {
        return new NothingFound;
    }
}
