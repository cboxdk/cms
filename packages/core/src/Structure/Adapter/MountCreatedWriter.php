<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\MountCreated;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreated as MountCreatedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreatedV1;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes MountCreated in the commit (PRD 5.8, 6.2 phase 7): the mount's row in `nodes`, of kind
 * mount with its source, active, at the context's version and time, below its parent, on the path
 * the mutation carries. It returns mount.created.
 *
 * It runs as the app role under the call's actor context, so `nodes_actor` takes the row only on a
 * path the actor's regions reach (PRD 5.10), and the row's path and the parent's foreign key keep
 * the mount in the subtree the command was authorized on; `nodes_mount` holds a mount to its
 * source, and `nodes_mount_source` keeps the source another node.
 */
#[Internal]
final readonly class MountCreatedWriter implements MutationWriter
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
        return MountCreated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof MountCreated) {
            throw new InvalidArgumentException(sprintf('The mount writer writes MountCreated, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->table('nodes')->insert([
            'id' => $mutation->node->toString(),
            'parent_id' => $mutation->parent->toString(),
            'kind' => NodeKind::Mount->value,
            'path' => $mutation->path->value,
            'mount_source_id' => $mutation->source->toString(),
            'lifecycle' => NodeLifecycle::Active->value,
            'version' => $context->version->value,
            'created_at' => Timestamps::of($context->at),
        ]);

        return [new MountCreatedEvent($context->version->value, new MountCreatedV1($mutation->node, $mutation->parent, $mutation->source))];
    }
}
