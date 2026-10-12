<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The nodes of a subtree, a page at a time, for the invalidation of a move (PRD 5.8, 9.4): a move
 * rewrites the path of every descendant, and the n- key of each is purged, in batches, however
 * many there are. It reads past the runner's regions, as the owner role, because a subtree may
 * lie outside the service actor's grants while its fragments still need purging, and runs under
 * the runner's actor context.
 */
#[Internal]
interface SubtreeNodes
{
    /**
     * The node and its descendants with an id above $after, null from the start, in id order, at
     * most $limit of them; empty when no node has the id. A page shorter than $limit is the last.
     *
     * @param  positive-int  $limit
     * @return list<NodeId>
     */
    public function below(NodeId $node, ?NodeId $after, int $limit): array;
}
