<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Access;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The action of GrantListName, on no surface: it registers the name grant.list for the panel registry of
 * the contribution tests and answers nothing a test reads.
 *
 * @implements QueryAction<GrantListName, AccessNamed>
 */
#[Action(handles: GrantListName::class)]
final readonly class GrantListNameAction implements QueryAction
{
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    public function handle(Query $query): AccessNamed
    {
        return new AccessNamed('grant.list');
    }
}
