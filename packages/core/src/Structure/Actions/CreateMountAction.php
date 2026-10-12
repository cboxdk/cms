<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\MountCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateMount;
use Cbox\Cms\Core\Structure\Domain\Dto\CreateMountAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use LogicException;
use Override;

/**
 * The write action of mount.create (PRD 5.8, 5.10, 6.2), exposed on every surface. resolve()
 * reads the mount node, which the command expects to be absent, the parent it goes below, the
 * source whose placements it shows and every ancestor of the parent, all past the actor's regions;
 * refusals() refuses what those reads rule out; plan() creates the mount below the parent with the
 * parent's path and the mount's own label below it.
 *
 * It is decided on the parent (PRD 5.10): a parent that exists but is not reached by the actor's
 * regions is unauthorized, because a mount created below it would be reached by the grants that
 * reach the parent. The source need not lie in the regions: a mount shows what its source shows the
 * public, nothing more, and the regional site that mounts the national section holds no rights
 * there. A parent that does not exist, is archived or is a mount, and a source that does not
 * exist, is the mount itself, is a mount, is archived, or is the parent or above it, so the mount
 * would sit below its own source, are validation_failed. The source and the ancestors are
 * aggregates, so a node.move that meanwhile puts the source above the parent changes the version
 * of a node read and is version_conflict (PRD 6.2 phase 7).
 *
 * @implements WriteAction<CreateMount, CreateMountAggregates>
 * @implements RefusesCommand<CreateMount, CreateMountAggregates>
 */
#[Action(handles: CreateMount::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreateMountAction implements RefusesCommand, WriteAction
{
    public function __construct(private NodeReader $nodes) {}

    /**
     * @param  CreateMount  $command
     */
    #[Override]
    public function resolve(Command $command): CreateMountAggregates
    {
        $parent = $this->nodes->node($command->parent);

        return new CreateMountAggregates(
            $command->node,
            $this->nodes->node($command->node),
            $command->parent,
            $parent,
            $command->source,
            $command->source->equals($command->parent) ? $parent : $this->nodes->node($command->source),
            $parent instanceof StoredNode ? $this->nodes->ancestors($command->parent) : [],
        );
    }

    /**
     * @param  CreateMount  $command
     * @param  CreateMountAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $parent = $aggregates->storedParent;
        $source = $aggregates->storedSource;

        if ($parent instanceof StoredNode && ! $parent->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('parent'), sprintf(
                'The node %s is not reached by the actor\'s grants, and a mount is created with the rights on the node it goes below (PRD 5.10).',
                $command->parent->toString(),
            ))];
        }

        $refusals = [];

        if (! $parent instanceof StoredNode) {
            $refusals[] = $this->invalid('parent', sprintf('No node %s exists to create a mount below.', $command->parent->toString()));
        } elseif ($parent->kind === NodeKind::Mount) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is a mount, which shows the placements of its source and holds no node of its own (PRD 5.8).', $command->parent->toString()));
        } elseif ($parent->archived()) {
            $refusals[] = $this->invalid('parent', sprintf('The node %s is archived, and archived structure takes no new node below it (PRD 6.4).', $command->parent->toString()));
        }

        if ($command->source->equals($command->node)) {
            $refusals[] = $this->invalid('source', sprintf('The mount %s names itself as its source; a mount shows another node\'s placements (PRD 5.8).', $command->node->toString()));
        } elseif (! $source instanceof StoredNode) {
            $refusals[] = $this->invalid('source', sprintf('No node %s exists for the mount to show.', $command->source->toString()));
        } elseif ($source->kind === NodeKind::Mount) {
            $refusals[] = $this->invalid('source', sprintf('The node %s is a mount, and a mount shows a node that holds placements of its own, not another mount (PRD 5.8).', $command->source->toString()));
        } elseif ($source->archived()) {
            $refusals[] = $this->invalid('source', sprintf('The node %s is archived, and a mount shows live structure (PRD 6.4).', $command->source->toString()));
        } elseif ($aggregates->sourceAbove()) {
            $refusals[] = $this->invalid('source', sprintf('The node %s is the node %s or above it, so the mount would sit below its own source and show itself (PRD 5.8).', $command->source->toString(), $command->parent->toString()));
        }

        return $refusals;
    }

    /**
     * @param  CreateMount  $command
     * @param  CreateMountAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $parent = $aggregates->storedParent;

        if (! $parent instanceof StoredNode) {
            throw new LogicException(sprintf('mount.create plans a mount below %s, which its refusals rule out when the parent does not exist.', $command->parent->toString()));
        }

        return new Plan(new MountCreated(
            $command->node,
            $command->parent,
            $command->source,
            $parent->childPath(NodeCreated::label($command->node)),
        ));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
