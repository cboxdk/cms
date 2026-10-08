<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Archives a node (PRD 5.8, 6.4), version 1 of node.archive: the node and the version the caller
 * read it at. An archived node is read-only structure: nothing is created below it, it takes no
 * route, and the content placed below it keeps the visibility it has.
 *
 * A node the actor's regions do not reach is unauthorized. A node that does not exist, or one at
 * another version than the caller read, is version_conflict. A node that is archived already, a
 * site root, which belongs to its site's registration, and a node below which a placement is
 * visible now or later are validation_failed: archiving never takes live content off the public
 * internet, which is what unpublishing is for.
 */
#[CommandName('node.archive', version: 1)]
#[Experimental]
final readonly class ArchiveNode implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public AggregateVersion $version,
    ) {}

    /**
     * The node, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->node, $this->version));
    }
}
