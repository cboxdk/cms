<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Structure\Domain\Dto\ListedNode;

/**
 * What node.list reads (PRD 5.8, 5.10), in the read transaction under its actor context: the nodes
 * the context's regions reach, and none without an actor.
 */
#[Internal]
interface NodeListing
{
    /**
     * At most $limit nodes the context reaches in tree order, a node before the nodes below it,
     * after the node $after when it is not null, each with its path label.
     *
     * @return list<ListedNode>
     */
    public function reached(?NodeId $after, int $limit): array;
}
