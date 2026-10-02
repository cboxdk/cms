<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Identity\Domain\ActorListing;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorList;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Override;

/**
 * actor.list (PRD 5.16, 12.2): a page of the staff actors with their state, version and profile,
 * read through ActorListing. The profile is personal data, so the result's codec leaves its values
 * out for a reader whose classification access does not allow personal. It reads one row more than
 * the page holds, to know whether a next page follows, and costs the rows it may return.
 *
 * @implements QueryAction<ListActors, ActorList>
 */
#[Action(handles: ListActors::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class ListActorsAction implements QueryAction
{
    public function __construct(private ActorListing $listing) {}

    /**
     * @param  ListActors  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->limit);
    }

    /**
     * @param  ListActors  $query
     */
    #[Override]
    public function handle(Query $query): ActorList
    {
        $actors = $this->listing->staff($query->after, $query->limit + 1);
        $page = array_slice($actors, 0, $query->limit);
        $last = $page === [] ? null : array_last($page);

        return new ActorList($page, count($actors) > $query->limit ? $last?->id : null);
    }
}
