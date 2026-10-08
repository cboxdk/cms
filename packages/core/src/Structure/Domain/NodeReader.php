<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use DateTimeImmutable;

/**
 * The reads of the node commands' resolve phase (PRD 5.8, 5.9, 6.2 phase 1). They run in the
 * command transaction under the call's actor context, and each reads past the actor's regions, as
 * the owner role, because the tree is one across the sites: a node a command names may lie outside
 * the regions, and the command must then be unauthorized rather than answered as if the node did
 * not exist (PRD 5.10); a route may be held by a node outside them, and a route resolves to one
 * node whatever the reader may reach (PRD 5.9); and a placement below a node may lie outside them,
 * while archiving the node must not change what the public reads.
 *
 * Each read is one statement, however many nodes are below the node or placements below it
 * (GUARDRAILS 4.1). None takes a lock; the commit locks what was read and checks its version.
 */
#[Internal]
interface NodeReader
{
    /**
     * The node with the id, with whether the actor's regions reach it, or null when no node has the
     * id.
     */
    public function node(NodeId $node): ?StoredNode;

    /**
     * The node that has the route on the site in the locale, or null when the route is free.
     */
    public function routeHolder(SiteId $site, Locale $locale, RequestPath $route): ?NodeId;

    /**
     * The route the node has on the site in the locale, or null when it has none there.
     */
    public function routeOf(SiteId $site, Locale $locale, NodeId $node): ?RequestPath;

    /**
     * A placement below the node, the node itself included, that is visible at the time or later,
     * or null when none is: what archiving the node weighs, so it never hides live content.
     */
    public function visiblePlacement(NodeId $node, DateTimeImmutable $at): ?PlacementId;
}
