<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * An entry is placed below a node of a site (PRD 5.7). An entry can have many placements, also
 * several in one site; moving content changes a placement's node and keeps its identity.
 */
#[Experimental]
final readonly class PlacementCreated implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public NodeId $node,
        public SiteId $site,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
