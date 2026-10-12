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
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeLabel;
use Cbox\Cms\Core\Structure\Domain\Dto\SetNodeLabelAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Override;

/**
 * The write action of node.set_label (PRD 5.8, 6.2), exposed on every surface. resolve() reads
 * the node past the actor's regions; refusals() refuses what that read rules out; plan() sets the
 * label in the language.
 *
 * A node that exists but is not reached by the actor's regions is unauthorized (PRD 5.10). An
 * archived node is validation_failed: archived structure is read-only. A node that does not exist
 * is version_conflict, because the command expects it at the version the caller read.
 *
 * @implements WriteAction<SetNodeLabel, SetNodeLabelAggregates>
 * @implements RefusesCommand<SetNodeLabel, SetNodeLabelAggregates>
 */
#[Action(handles: SetNodeLabel::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class SetNodeLabelAction implements RefusesCommand, WriteAction
{
    public function __construct(private NodeReader $nodes) {}

    /**
     * @param  SetNodeLabel  $command
     */
    #[Override]
    public function resolve(Command $command): SetNodeLabelAggregates
    {
        return new SetNodeLabelAggregates($command->node, $this->nodes->node($command->node), $command->locale);
    }

    /**
     * @param  SetNodeLabel  $command
     * @param  SetNodeLabelAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $node = $aggregates->current;

        if (! $node instanceof StoredNode) {
            return [];
        }

        if (! $node->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('node'), sprintf(
                'The node %s is not reached by the actor\'s grants, and a label is set with the rights on its node (PRD 5.10).',
                $command->node->toString(),
            ))];
        }

        if ($node->archived()) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('node'), sprintf(
                'The node %s is archived, and archived structure takes no label (PRD 6.4).',
                $command->node->toString(),
            ))];
        }

        return [];
    }

    /**
     * @param  SetNodeLabel  $command
     * @param  SetNodeLabelAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new NodeLabelSet($command->node, $command->locale, $command->label->value));
    }
}
