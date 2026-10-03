<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Access\Domain\AccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Override;

/**
 * grant.list (PRD 5.10, 12.2): a page of the grants that have not ended on the nodes the actor's
 * regions reach where a role of it whose permissions name grant.list reaches them, each with its
 * actor's profile where the reader may read it, its role's handle, its node's path label and its
 * locales, read through AccessListings. The profile is personal
 * data, so the result's codec leaves its values out for a reader whose classification access does
 * not allow personal. It reads one row more than the page holds, to know whether a next page
 * follows, and costs the rows it may return.
 *
 * @implements QueryAction<ListGrants, GrantList>
 */
#[Action(handles: ListGrants::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class ListGrantsAction implements QueryAction
{
    public function __construct(private AccessListings $listings) {}

    /**
     * @param  ListGrants  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->limit);
    }

    /**
     * @param  ListGrants  $query
     */
    #[Override]
    public function handle(Query $query): GrantList
    {
        $grants = $this->listings->grants($query->after, $query->limit + 1);
        $page = array_slice($grants, 0, $query->limit);
        $last = $page === [] ? null : array_last($page);

        return new GrantList($page, count($grants) > $query->limit ? $last?->id : null);
    }
}
