<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\NodeArchived;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Dto\ArchiveNodeAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Override;

/**
 * The write action of node.archive (PRD 5.8, 6.4, 6.2), exposed on every surface. resolve() reads
 * the node past the actor's regions and, at the Clock's time, a placement below it that is visible
 * then or later; refusals() refuses what those reads rule out; plan() archives the node.
 *
 * A node that exists but is not reached by the actor's regions is unauthorized (PRD 5.10). A node
 * that is archived already, a site root, which belongs to its site's registration, and a node below
 * which a placement is visible now or later are validation_failed: archiving is for structure that
 * holds nothing the public reads, and taking content off the public internet is what unpublishing
 * is for. The writer reads the placements again in the commit, because a window opened on one of
 * them meanwhile does not change the node's version.
 *
 * @implements WriteAction<ArchiveNode, ArchiveNodeAggregates>
 * @implements RefusesCommand<ArchiveNode, ArchiveNodeAggregates>
 */
#[Action(handles: ArchiveNode::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ArchiveNodeAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private NodeReader $nodes,
        private Clock $clock,
    ) {}

    /**
     * @param  ArchiveNode  $command
     */
    #[Override]
    public function resolve(Command $command): ArchiveNodeAggregates
    {
        $at = $this->clock->now();
        $node = $this->nodes->node($command->node);

        return new ArchiveNodeAggregates(
            $command->node,
            $node,
            $node instanceof StoredNode ? $this->nodes->visiblePlacement($command->node, $at) : null,
            $at,
        );
    }

    /**
     * @param  ArchiveNode  $command
     * @param  ArchiveNodeAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $node = $aggregates->current;

        if (! $node instanceof StoredNode) {
            return [];
        }

        if (! $node->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, null, sprintf(
                'The node %s is not reached by the actor\'s grants, and a node is archived with the rights on it (PRD 5.10).',
                $command->node->toString(),
            ))];
        }

        $refusals = [];

        if ($node->archived()) {
            $refusals[] = $this->invalid(sprintf('The node %s is archived already.', $command->node->toString()));
        }

        if ($node->kind === NodeKind::Site) {
            $refusals[] = $this->invalid(sprintf(
                'The node %s is the root of a site, which belongs to the site\'s registration and is removed by taking the site out of the configuration (PRD 11.14).',
                $command->node->toString(),
            ));
        }

        if ($aggregates->visible instanceof PlacementId) {
            $refusals[] = $this->invalid(sprintf(
                'The placement %s below the node %s is visible now or later, and archiving never takes content off the public internet; unpublish the content first (PRD 6.4).',
                $aggregates->visible->toString(),
                $command->node->toString(),
            ));
        }

        return $refusals;
    }

    /**
     * @param  ArchiveNode  $command
     * @param  ArchiveNodeAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new NodeArchived($command->node));
    }

    private function invalid(string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath('node'), $message);
    }
}
