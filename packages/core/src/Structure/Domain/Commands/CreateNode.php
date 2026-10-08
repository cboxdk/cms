<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Override;

/**
 * Creates a node below another (PRD 5.8), version 1 of node.create: the caller's id of the new
 * node, the node it goes below, and the kind of structure it is, a section, a page, a list or a
 * storage folder. Its path in the tree is the parent's with the new node's own label below it, so
 * the grants that reach the parent reach the new node too (PRD 5.10).
 *
 * The caller makes the node's id, so a repeat of the call with the same idempotency key is the same
 * node, and the command expects the node not to exist: a create of an id that exists is
 * version_conflict (invariant 11). A parent the actor's regions do not reach is unauthorized; a
 * parent that does not exist, one that is archived or a mount, and the kinds site and mount, which
 * a site's registration and mount.create make, are validation_failed.
 */
#[CommandName('node.create', version: 1)]
#[Experimental]
final readonly class CreateNode implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public NodeKind $kind,
    ) {}

    /**
     * The node, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->node));
    }
}
