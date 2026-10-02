<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;

/**
 * A node as node.list gives it (PRD 5.8): its id, its parent or null for the root of a tree, its
 * kind, the site whose tree it is in with the site's handle, or null for a tree that is no site's,
 * and its path label: one segment per node from the root of its tree down to it, joined by `/`,
 * the site's handle for the root of a site's tree, and below it the last segment of the node's
 * route, or the node's kind when it has none, such as north/news/section.
 */
#[Experimental]
final readonly class ListedNode
{
    public function __construct(
        public NodeId $id,
        public ?NodeId $parent,
        public NodeKind $kind,
        public ?SiteId $site,
        public ?SiteHandle $siteHandle,
        public string $label,
    ) {}
}
