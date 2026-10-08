<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A node is archived (PRD 5.8, 6.4): the node becomes read-only structure. Nothing is created below
 * it, it takes no route, and the content placed below it keeps the visibility it has. The kernel
 * archives a node only while no placement below it is visible now or later, so archiving never
 * changes what the public reads.
 */
#[Experimental]
final readonly class NodeArchived implements Mutation
{
    public function __construct(public NodeId $node) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }
}
