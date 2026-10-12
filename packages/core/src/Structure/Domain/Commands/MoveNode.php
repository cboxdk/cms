<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Moves a node with its subtree under another parent (PRD 5.8), version 1 of node.move: the node,
 * the version the caller read it at, and the parent it goes below. The kernel rewrites the path of
 * every descendant in the same changeset, so the subtree stays one. Routes belong to their nodes
 * and move with them, so a move within a site changes no URL.
 *
 * The issuing actor needs node.move on both the parent the node leaves and the parent it goes
 * below, and a node, or a parent, that its grants do not reach is unauthorized. A node that does
 * not exist, or one at another version than the caller read, is version_conflict, as is a move
 * whose node, parents or ancestors changed before the commit, so two moves that would make a cycle
 * together commit one after the other and the second is refused. A site root, an archived node, a
 * parent that does not exist, is archived or is a mount, a parent that is the node itself or below
 * it, the parent the node is below already, and a move under another site while the node or a
 * descendant has a route are validation_failed. A move under another site while a placement below
 * the node is visible now or later makes that content public there, which an agent or a token may
 * not do (agent_visibility_forbidden, invariant 18).
 */
#[CommandName('node.move', version: 1)]
#[Experimental]
final readonly class MoveNode implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public AggregateVersion $version,
        public NodeId $parent,
    ) {}

    /**
     * The node, at the version the caller read. The parents and every ancestor of the new parent
     * are aggregates the action reads too, so the commit locks them and checks their versions, but
     * the caller gives no version for them.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->node, $this->version));
    }
}
