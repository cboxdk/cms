<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeArchived;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchived as NodeArchivedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchivedV1;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes NodeArchived in the commit (PRD 5.8, 6.4, 6.2 phase 7): the node's row in `nodes` set to
 * the lifecycle archived at the context's version, as the app role under the call's actor context,
 * so `nodes_actor` lets it through only for a node the actor's regions reach (PRD 5.10).
 *
 * It reads the placements below the node once more, at the changeset's time, before it writes: a
 * window opened on one of them between the action's read and the commit does not change the node's
 * version, and archiving a node that holds visible content would hide it. A placement that is
 * visible then rolls the commit back, so the invariant holds even in that race; the caller runs the
 * command again and gets the refusal from the action. It returns node.archived.
 */
#[Internal]
final readonly class NodeArchivedWriter implements MutationWriter
{
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
        return NodeArchived::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeArchived) {
            throw new InvalidArgumentException(sprintf('The node archive writer writes NodeArchived, not %s.', $mutation::class));
        }

        $visible = new PostgresNodeReader($this->connections, $this->connection)->visiblePlacement($mutation->node, $context->at);

        if ($visible instanceof PlacementId) {
            throw new LogicException(sprintf(
                'The placement %s below the node %s became visible while node.archive ran, and an archived node holds nothing the public reads (PRD 6.4).',
                $visible->toString(),
                $mutation->node->toString(),
            ));
        }

        $changed = $this->connections->connection($this->connection)
            ->table('nodes')
            ->where('id', $mutation->node->toString())
            ->where('lifecycle', NodeLifecycle::Active->value)
            ->update(['lifecycle' => NodeLifecycle::Archived->value, 'version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The node %s is not an active node the actor reaches, so it was not archived.', $mutation->node->toString()));
        }

        return [new NodeArchivedEvent($context->version->value, new NodeArchivedV1($mutation->node))];
    }
}
