<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeMoved;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Commands\MoveNode;
use Cbox\Cms\Core\Structure\Domain\Dto\MoveNodeAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Override;

/**
 * The write action of node.move (PRD 5.8, 5.10, 6.2, invariant 18), exposed on every surface.
 * resolve() reads the node, the parent it goes below, the parent it leaves and every ancestor of
 * the new parent, all past the actor's regions, and, when the move lands under another site's
 * root, whether a node of the subtree has a route and whether a placement below the node is
 * visible at the Clock's time or later; refusals() refuses what those reads rule out; plan() moves
 * the node.
 *
 * The ancestors are read so the commit locks the whole path the node lands under: two moves that
 * would make a cycle together (A under B while B goes under A) each read the other's node, so the
 * second finds it at another version and is version_conflict. A node, or a parent, the regions do
 * not reach is unauthorized (PRD 5.10), because the node is taken from the one parent and put
 * below the other. A site root, an archived node, a parent that is absent, archived or a mount, a
 * parent that is the node itself or below it, the parent the node is below already, and a move to
 * another site while the node or a descendant has a route are validation_failed: a route belongs
 * to its node and its site, and a move within a site changes no URL. The plan's NodeMoved makes
 * content public when it takes a visible placement to another site, which the pipeline refuses an
 * agent or a token with agent_visibility_forbidden; the writer reads the placements once more in
 * the commit, because a window opened meanwhile does not change the node's version.
 *
 * @implements WriteAction<MoveNode, MoveNodeAggregates>
 * @implements RefusesCommand<MoveNode, MoveNodeAggregates>
 */
#[Action(handles: MoveNode::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class MoveNodeAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private NodeReader $nodes,
        private Clock $clock,
    ) {}

    /**
     * @param  MoveNode  $command
     */
    #[Override]
    public function resolve(Command $command): MoveNodeAggregates
    {
        $at = $this->clock->now();
        $node = $this->nodes->node($command->node);
        $parent = $command->parent->equals($command->node) ? $node : $this->nodes->node($command->parent);
        $oldParent = $node?->parent instanceof NodeId ? $this->nodes->node($node->parent) : null;
        $ancestors = $parent instanceof StoredNode ? $this->nodes->ancestors($command->parent) : [];
        $crossing = $node instanceof StoredNode && $parent instanceof StoredNode && $node->rootLabel() !== $parent->rootLabel();

        return new MoveNodeAggregates(
            $command->node,
            $node,
            $command->parent,
            $parent,
            $oldParent,
            $ancestors,
            $crossing ? $this->nodes->routedBelow($command->node) : null,
            $crossing ? $this->nodes->visiblePlacement($command->node, $at) : null,
            $at,
        );
    }

    /**
     * @param  MoveNode  $command
     * @param  MoveNodeAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $node = $aggregates->current;
        $parent = $aggregates->newParent;

        if (! $node instanceof StoredNode) {
            return [];
        }

        if (! $node->reachable) {
            return [$this->unauthorized('node', sprintf('The node %s is not reached by the actor\'s grants, and a node is moved with the rights on both its parents (PRD 5.10).', $command->node->toString()))];
        }

        if ($parent instanceof StoredNode && ! $parent->reachable) {
            return [$this->unauthorized('parent', sprintf('The node %s is not reached by the actor\'s grants, and a node is moved with the rights on both its parents (PRD 5.10).', $command->parent->toString()))];
        }

        if ($aggregates->oldParent instanceof StoredNode && ! $aggregates->oldParent->reachable) {
            return [$this->unauthorized('node', sprintf('The parent %s of the node %s is not reached by the actor\'s grants, and a node is moved with the rights on both its parents (PRD 5.10).', $aggregates->oldParent->id->toString(), $command->node->toString()))];
        }

        if ($node->kind === NodeKind::Site) {
            return [$this->invalid('node', sprintf('The node %s is the root of a site, which stays at the top of its tree (PRD 5.8).', $command->node->toString()))];
        }

        $refusals = [];

        if ($node->archived()) {
            $refusals[] = $this->invalid('node', sprintf('The node %s is archived, and archived structure is not moved (PRD 6.4).', $command->node->toString()));
        }

        if (! $parent instanceof StoredNode) {
            $refusals[] = $this->invalid('parent', sprintf('No node %s exists to move the node below.', $command->parent->toString()));

            return $refusals;
        }

        if ($node->path->contains($parent->path)) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is the node %s itself or below it, and a node is not moved below itself (PRD 5.8).', $command->parent->toString(), $command->node->toString()));
        } elseif ($node->parent instanceof NodeId && $node->parent->equals($parent->id)) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is below the node %s already.', $command->node->toString(), $command->parent->toString()));
        }

        if ($parent->kind === NodeKind::Mount) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is a mount, which shows its source and holds no node of its own (PRD 5.8).', $command->parent->toString()));
        }

        if ($parent->archived()) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is archived, and nothing is put below archived structure (PRD 6.4).', $command->parent->toString()));
        }

        if ($aggregates->routed instanceof NodeId) {
            $refusals[] = $this->invalid('parent', sprintf(
                'The node %s is under another site than the node %s, and the node %s below it has a route there; a route belongs to the site of its node (PRD 5.9), so take the routes off the subtree first.',
                $command->parent->toString(),
                $command->node->toString(),
                $aggregates->routed->toString(),
            ));
        }

        return $refusals;
    }

    /**
     * @param  MoveNode  $command
     * @param  MoveNodeAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $node = $aggregates->current;
        $parent = $aggregates->newParent;

        if (! $node instanceof StoredNode || ! $parent instanceof StoredNode || ! $node->parent instanceof NodeId) {
            return new Plan;
        }

        return new Plan(new NodeMoved(
            $command->node,
            $node->parent,
            $command->parent,
            $parent->childPath(NodeCreated::label($command->node)),
            $aggregates->crossesSites(),
            $aggregates->visible !== null,
        ));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }

    private function unauthorized(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::Unauthorized, new FieldPath($path), $message);
    }
}
