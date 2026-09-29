<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * Another command's planner, which SaveNoteAction composes: the plan that places an entry below a
 * node. It computes a plan and nothing else; it never writes or calls another action.
 */
final readonly class NotePlacements
{
    public function place(PlacementId $placement, EntryId $note, NodeId $node, SiteId $site): Plan
    {
        return new Plan(new PlacementCreated($placement, $note, $node, $site));
    }
}
