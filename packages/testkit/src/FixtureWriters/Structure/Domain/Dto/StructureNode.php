<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * A node the structure fixtures wrote: its id and its path in the tree, which a test gives an
 * access region or a grant.
 */
#[Experimental]
final readonly class StructureNode
{
    public function __construct(
        public NodeId $id,
        public NodePath $path,
    ) {}
}
