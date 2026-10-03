<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The query action of tally.audit.
 *
 * @implements QueryAction<AuditTally, TallyCount>
 */
#[Action(handles: AuditTally::class)]
final readonly class AuditTallyAction implements QueryAction
{
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(0);
    }

    public function handle(Query $query): TallyCount
    {
        return new TallyCount(0, '', '');
    }
}
