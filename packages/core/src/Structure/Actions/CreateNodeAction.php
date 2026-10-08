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
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Dto\CreateNodeAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use LogicException;
use Override;

/**
 * The write action of node.create (PRD 5.8, 6.2), exposed on every surface. resolve() reads the
 * node, which the command expects to be absent, and the parent it goes below, both past the actor's
 * regions; refusals() refuses what those reads rule out; plan() creates the node below the parent
 * with the parent's path and the node's own label below it.
 *
 * It is decided on the parent (PRD 5.10): a parent that exists but is not reached by the actor's
 * regions is unauthorized, because a node created below it would be reached by the grants that
 * reach the parent. A parent that does not exist, a parent that is archived or a mount, which shows
 * its source's placements and holds nothing of its own, and the kinds site and mount, which a
 * site's registration and mount.create make, are validation_failed.
 *
 * @implements WriteAction<CreateNode, CreateNodeAggregates>
 * @implements RefusesCommand<CreateNode, CreateNodeAggregates>
 */
#[Action(handles: CreateNode::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreateNodeAction implements RefusesCommand, WriteAction
{
    public function __construct(private NodeReader $nodes) {}

    /**
     * @param  CreateNode  $command
     */
    #[Override]
    public function resolve(Command $command): CreateNodeAggregates
    {
        return new CreateNodeAggregates(
            $command->node,
            $this->nodes->node($command->node),
            $command->parent,
            $this->nodes->node($command->parent),
        );
    }

    /**
     * @param  CreateNode  $command
     * @param  CreateNodeAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $parent = $aggregates->storedParent;
        $path = new FieldPath('parent');

        if ($parent instanceof StoredNode && ! $parent->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, $path, sprintf(
                'The node %s is not reached by the actor\'s grants, and a node is created with the rights on the node it goes below (PRD 5.10).',
                $command->parent->toString(),
            ))];
        }

        $refusals = [];

        if (! $parent instanceof StoredNode) {
            $refusals[] = $this->invalid($path, sprintf('No node %s exists to create a node below.', $command->parent->toString()));
        } elseif ($parent->kind === NodeKind::Mount) {
            $refusals[] = $this->invalid($path, sprintf('The node %s is a mount, which shows the placements of its source and holds no node of its own (PRD 5.8).', $command->parent->toString()));
        } elseif ($parent->archived()) {
            $refusals[] = $this->invalid($path, sprintf('The node %s is archived, and archived structure takes no new node below it (PRD 6.4).', $command->parent->toString()));
        }

        if (! in_array($command->kind->value, NodeCreated::KINDS, true)) {
            $refusals[] = $this->invalid(new FieldPath('kind'), sprintf(
                'A node below another is one of %s, not %s: a site root comes with the site\'s registration, and a mount with mount.create.',
                implode(', ', NodeCreated::KINDS),
                $command->kind->value,
            ));
        }

        return $refusals;
    }

    /**
     * @param  CreateNode  $command
     * @param  CreateNodeAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $parent = $aggregates->storedParent;

        if (! $parent instanceof StoredNode) {
            throw new LogicException(sprintf('node.create plans a node below %s, which its refusals rule out when the parent does not exist.', $command->parent->toString()));
        }

        return new Plan(new NodeCreated(
            $command->node,
            $command->parent,
            $command->kind->value,
            $parent->childPath(NodeCreated::label($command->node)),
        ));
    }

    private function invalid(FieldPath $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, $path, $message);
    }
}
