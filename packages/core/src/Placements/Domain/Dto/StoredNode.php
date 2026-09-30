<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A node as a placement command reads it (PRD 5.8): its version, its path in the tree and whether
 * it is a mount, which shows another node's placements and holds none of its own.
 */
#[Internal]
final readonly class StoredNode
{
    public function __construct(
        public NodeId $id,
        public AggregateVersion $version,
        public NodePath $path,
        public bool $mount,
    ) {}
}
