<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\ReadRejected;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Override;

/**
 * entry.get (PRD 5.4, 5.7, 13.4): one entry with its type, home node and lifecycle, every variant
 * head with the fields of its draft revision, and its placements with their locales and windows,
 * read through EntryListing. Every actor may run it. The query pipeline strips each head's
 * fields to the reader's classification access. An entry that is absent or whose home the reader
 * neither reaches nor owns rejects the read with entry_not_found, which tells the two apart for no
 * one (PRD 5.10). It costs the three statements it reads.
 *
 * @implements QueryAction<GetEntry, EntryDetail>
 */
#[Action(handles: GetEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp])]
#[Internal]
final readonly class GetEntryAction implements QueryAction
{
    public const int COST = 3;

    public function __construct(private EntryListing $listing) {}

    /**
     * @param  GetEntry  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @param  GetEntry  $query
     *
     * @throws ReadRejected with entry_not_found when the entry is absent or its home is out of the reader's reach
     */
    #[Override]
    public function handle(Query $query): EntryDetail
    {
        return $this->listing->find($query->entry) ?? throw ReadRejected::with(
            ErrorCode::EntryNotFound,
            sprintf('No entry %s is within reach: none exists, or its home lies outside the regions of your grants and you do not own it.', $query->entry->toString()),
            FieldPath::fromString('entry'),
        );
    }
}
