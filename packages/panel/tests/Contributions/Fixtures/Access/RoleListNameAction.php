<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Access;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The action of RoleListName, on no surface: it registers the name role.list for the panel registry of
 * the contribution tests and answers nothing a test reads.
 *
 * @implements QueryAction<RoleListName, AccessNamed>
 */
#[Action(handles: RoleListName::class)]
final readonly class RoleListNameAction implements QueryAction
{
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    public function handle(Query $query): AccessNamed
    {
        return new AccessNamed('role.list');
    }
}
