<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The step of a registry fixture's query action, which finds nothing: the registry tests read only
 * the action's declaration.
 */
trait FindsNothing
{
    public function handle(Query $query): NothingFound
    {
        return new NothingFound;
    }
}
