<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Override;

/**
 * A node moves with its subtree under another parent (PRD 5.8): the node, the parent it leaves,
 * the parent it goes below, and its new path in the tree, the new parent's path with the node's
 * own label below it. The kernel rewrites the path of every descendant in the same changeset, so
 * the subtree stays one, and every grant that reaches the new parent reaches the moved nodes
 * (PRD 5.10). Routes belong to their nodes and move with them, so a move within a site changes no
 * URL.
 *
 * A move that lands under another site's root while a placement below the node is visible now or
 * later makes that content public on the other site, so the mutation makes content public then,
 * and an agent or a token may not plan it (invariant 18); $crossesSites and $visible say so, as
 * the action read them.
 */
#[Experimental]
final readonly class NodeMoved implements ChangesPublicVisibility
{
    /**
     * @throws InvalidMutation for a path that is not below another node and ending in the node's
     *                         own label, or a move whose parents are one node
     */
    public function __construct(
        public NodeId $node,
        public NodeId $from,
        public NodeId $to,
        public NodePath $path,
        public bool $crossesSites,
        public bool $visible,
    ) {
        if (! str_contains($path->value, '.') || ! str_ends_with($path->value, '.'.NodeCreated::label($node))) {
            throw InvalidMutation::nodePath($node, $path);
        }

        if ($from->equals($to)) {
            throw InvalidMutation::nodeMoveWithoutChange($node, $to);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }

    /**
     * A move makes content public when it takes a visible placement to another site.
     */
    #[Override]
    public function makesPublic(): bool
    {
        return $this->crossesSites && $this->visible;
    }
}
