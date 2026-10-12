<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Creates a mount below a node (PRD 5.8), version 1 of mount.create: the caller's id of the new
 * mount node, the node it goes below, and the source whose placements it shows. A mount is
 * identical with its source: same slugs, same visibility, canonical at the source, and it creates
 * no rows for the content it shows. Its path in the tree is the parent's with the mount's own
 * label below it, so the grants that reach the parent reach the mount too (PRD 5.10).
 *
 * The caller makes the mount's id, so a repeat of the call with the same idempotency key is the
 * same mount, and the command expects the node not to exist: a create of an id that exists is
 * version_conflict (invariant 11). A parent the actor's regions do not reach is unauthorized; a
 * parent that does not exist, is archived or is a mount, and a source that does not exist, is the
 * mount itself, is a mount, is archived, or is the parent or an ancestor of it, are
 * validation_failed. The source and the ancestors of the parent are aggregates the action reads,
 * so a node.move that meanwhile puts the source above the mount is version_conflict.
 */
#[CommandName('mount.create', version: 1)]
#[Experimental]
final readonly class CreateMount implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public NodeId $source,
    ) {}

    /**
     * The mount node, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->node));
    }
}
