<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;

/**
 * The longest route prefix of a path (PRD 5.9 step 2): the route, the node it reaches, the node's
 * kind and, for a mount, the node whose placements it shows (PRD 5.8).
 */
#[Internal]
final readonly class RouteMatch
{
    public function __construct(
        public string $route,
        public NodeId $node,
        public NodeKind $kind,
        public ?NodeId $mountSource,
    ) {}
}
