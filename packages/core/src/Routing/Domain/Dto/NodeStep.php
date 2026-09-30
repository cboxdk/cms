<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;

/**
 * The node the route reaches (PRD 5.8, 5.9 step 2) and its kind.
 */
#[Experimental]
final readonly class NodeStep
{
    public function __construct(
        public NodeId $node,
        public NodeKind $kind,
    ) {}
}
