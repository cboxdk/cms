<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The query action of tally.heavy, whose cost is above every budget, so the query pipeline rejects
 * it with query_over_budget before it runs.
 *
 * @implements QueryAction<HeavyTally, TallyCount>
 */
#[Action(handles: HeavyTally::class)]
final readonly class HeavyTallyAction implements QueryAction
{
    public const int COST = 1_000_000;

    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    public function handle(Query $query): TallyCount
    {
        return new TallyCount(0, '', '');
    }
}
