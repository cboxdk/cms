<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryList;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Override;

/**
 * entry.list (PRD 5.4, 8.8, 13.4): a page of the entries the actor's regions reach or it owns,
 * across types, newest first, each with its system columns, filtered by type, node subtree and
 * the shared variant's release state, read through EntryListing. Every actor may run it. It
 * reads one row more than the page holds, to know whether a next page follows, and costs the
 * rows it may return.
 *
 * @implements QueryAction<ListEntries, EntryList>
 */
#[Action(handles: ListEntries::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp])]
#[Internal]
final readonly class ListEntriesAction implements QueryAction
{
    public function __construct(private EntryListing $listing) {}

    /**
     * @param  ListEntries  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->limit);
    }

    /**
     * @param  ListEntries  $query
     */
    #[Override]
    public function handle(Query $query): EntryList
    {
        $entries = $this->listing->page($query, $query->limit + 1);
        $page = array_slice($entries, 0, $query->limit);
        $last = $page === [] ? null : array_last($page);

        return new EntryList($page, count($entries) > $query->limit ? $last?->id : null);
    }
}
