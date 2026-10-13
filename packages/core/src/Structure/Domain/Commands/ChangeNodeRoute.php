<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Override;

/**
 * Sets a node's route on a site in one language (PRD 5.9), version 2 of node.set_route: the node,
 * the version the caller read it at, the site, the language and the route, `/` or `/`-separated
 * segments without a trailing slash. It takes the same document as version 1 and differs in what
 * it does with a node that has a route on the site in that language already: version 1 refuses it,
 * version 2 moves the route and records the one the node had as a prefix redirect, so every URL
 * below the old route answers 301 below the new one in one hop, also after several changes. A
 * node without a route gets one, as version 1 gives it.
 *
 * A route that moves makes nothing public that was not, so an agent may move one; a route a node
 * gets for the first time makes it reachable from the public internet, and an agent is refused
 * with agent_visibility_forbidden (invariant 18). A node the actor's regions do not reach is
 * unauthorized, a node at another version than the caller read is version_conflict, as is a route
 * another command claimed between the read and the commit, and a route another node holds already
 * is node_route_taken. A node that does not exist, one that is archived, a site the installation
 * does not have, a language the site does not publish in, a node outside the site's tree, and the
 * route the node has already are validation_failed.
 */
#[CommandName('node.set_route', version: 2)]
#[Experimental]
final readonly class ChangeNodeRoute implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public AggregateVersion $version,
        public SiteId $site,
        public Locale $locale,
        public RequestPath $route,
    ) {}

    /**
     * The node, at the version the caller read; the route is read as an aggregate too, without a
     * version from the caller, as version 1 reads it.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->node, $this->version));
    }
}
