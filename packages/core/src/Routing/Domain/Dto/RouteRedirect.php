<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;

/**
 * A prefix redirect (PRD 5.9): the longest route a node of the site had before in the locale that
 * is a prefix of a path, from the route history, with the node, its kind, a mount's source and the
 * route the node has on the site in the locale now, null when it has none there any more.
 */
#[Internal]
final readonly class RouteRedirect
{
    public function __construct(
        public string $from,
        public NodeId $node,
        public NodeKind $kind,
        public ?NodeId $mountSource,
        public ?string $current,
    ) {}
}
