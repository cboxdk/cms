<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Events\NodeCreated as NodeCreatedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\NodeCreatedV1;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes NodeCreated in the commit (PRD 5.8, 6.2 phase 7): the node's row in `nodes`, active, at
 * the context's version and time, below its parent, on the path the mutation carries, with no mount
 * source. It returns node.created.
 *
 * It runs as the app role under the call's actor context, so `nodes_actor` takes the row only on a
 * path the actor's regions reach (PRD 5.10), and the row's path and the parent's foreign key keep
 * the node in the subtree the command was authorized on.
 */
#[Internal]
final readonly class NodeCreatedWriter implements MutationWriter
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
        return NodeCreated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeCreated) {
            throw new InvalidArgumentException(sprintf('The node writer writes NodeCreated, not %s.', $mutation::class));
        }

        $kind = NodeKind::from($mutation->kind);

        $this->connections->connection($this->connection)->table('nodes')->insert([
            'id' => $mutation->node->toString(),
            'parent_id' => $mutation->parent->toString(),
            'kind' => $kind->value,
            'path' => $mutation->path->value,
            'mount_source_id' => null,
            'lifecycle' => NodeLifecycle::Active->value,
            'version' => $context->version->value,
            'created_at' => Timestamps::of($context->at),
        ]);

        return [new NodeCreatedEvent($context->version->value, new NodeCreatedV1($mutation->node, $mutation->parent, $kind))];
    }
}
