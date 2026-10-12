<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A mount is created below a node (PRD 5.8): the new node of kind mount, its parent, the source
 * whose placements it shows, and its path in the tree, the parent's path with the node's own label
 * below it, as NodeCreated puts a node. A mount creates no rows for the content it shows: it is
 * identical with its source, same slugs, same visibility, canonical at the source, and a site that
 * needs a difference makes a placement of its own instead.
 *
 * The source is another node than the mount; the action rules out a source that is a mount, an
 * ancestor of the mount or archived, which the kernel cannot see from the mutation alone.
 */
#[Experimental]
final readonly class MountCreated implements Mutation
{
    /**
     * @throws InvalidMutation for a source that is the mount itself, or a path that is not below
     *                         another node and ending in the node's own label
     */
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public NodeId $source,
        public NodePath $path,
    ) {
        if ($source->equals($node)) {
            throw InvalidMutation::mountSource($node, $source);
        }

        if (! str_contains($path->value, '.') || ! str_ends_with($path->value, '.'.NodeCreated::label($node))) {
            throw InvalidMutation::nodePath($node, $path);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }
}
