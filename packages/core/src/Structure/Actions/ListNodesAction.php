<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Structure\Domain\Dto\NodeList;
use Cbox\Cms\Core\Structure\Domain\NodeListing;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Override;

/**
 * node.list (PRD 5.8, 5.10): a page of the nodes the actor's regions reach, in tree order, each
 * with its site and path label, read through NodeListing. Every actor may run it. It reads one row
 * more than the page holds, to know whether a next page follows, and costs the rows it may return.
 *
 * @implements QueryAction<ListNodes, NodeList>
 */
#[Action(handles: ListNodes::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class ListNodesAction implements QueryAction
{
    public function __construct(private NodeListing $listing) {}

    /**
     * @param  ListNodes  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->limit);
    }

    /**
     * @param  ListNodes  $query
     */
    #[Override]
    public function handle(Query $query): NodeList
    {
        $nodes = $this->listing->reached($query->after, $query->limit + 1);
        $page = array_slice($nodes, 0, $query->limit);
        $last = $page === [] ? null : array_last($page);

        return new NodeList($page, count($nodes) > $query->limit ? $last?->id : null);
    }
}
