<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeMoved;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMoved as NodeMovedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMovedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes NodeMoved in the commit (PRD 5.8, 6.2 phase 7), through the owner function
 * `cms_structure_move_node` (see the migration that adds node.move): one statement that puts the
 * node below its new parent at the context's version and rewrites the path of every descendant,
 * each one version up, however many there are, and raises when a node below the old path is left,
 * which a node created below a descendant between the statement's snapshot and its row locks would
 * be. The function runs only in the transaction of a node.move changeset by the context's actor.
 *
 * A move to another site while no placement below the node was visible reads the placements once
 * more, at the changeset's time, before it writes: a window opened on one of them between the
 * action's read and the commit does not change the node's version, and the move would then take
 * live content to the other site unplanned. A placement that is visible then rolls the commit
 * back, so the invariant holds even in that race; the caller runs the command again and the
 * action plans the move as one that makes content public. It returns node.moved.
 */
#[Internal]
final readonly class NodeMovedWriter implements MutationWriter
{
    public const string MOVE = 'select cms_structure_move_node(?::uuid, ?::uuid, ?::ltree, ?::bigint, ?::uuid) as moved';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return NodeMoved::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeMoved) {
            throw new InvalidArgumentException(sprintf('The node move writer writes NodeMoved, not %s.', $mutation::class));
        }

        if ($mutation->crossesSites && ! $mutation->visible) {
            $visible = new PostgresNodeReader($this->connections, $this->connection)->visiblePlacement($mutation->node, $context->at);

            if ($visible instanceof PlacementId) {
                throw new LogicException(sprintf(
                    'The placement %s below the node %s became visible while node.move ran, and a move to another site takes visible content public there only when planned so (invariant 18).',
                    $visible->toString(),
                    $mutation->node->toString(),
                ));
            }
        }

        $row = $this->connections->connection($this->connection)->selectOne(self::MOVE, [
            $mutation->node->toString(),
            $mutation->to->toString(),
            $mutation->path->value,
            $context->version->value,
            $context->changesetId->toString(),
        ], false);
        $moved = $row === null ? 0 : NodeRows::integer(NodeRows::object($row), 'moved');

        if ($moved < 1) {
            throw new LogicException(sprintf('The node %s was not moved below %s.', $mutation->node->toString(), $mutation->to->toString()));
        }

        return [new NodeMovedEvent($context->version->value, new NodeMovedV1($mutation->node, $mutation->from, $mutation->to))];
    }
}
