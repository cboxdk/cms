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
 * Gives a node its route on a site in one language (PRD 5.9), version 1 of node.set_route: the
 * node, the version the caller read it at, the site, the language and the route, `/` or
 * `/`-separated segments without a trailing slash. The route is the path the public asks for: the
 * longest route that is a prefix of a request's path decides the node, and the rest of the path is
 * the slug of a placement below it.
 *
 * A route makes the node, and whatever is live below it, reachable from the public internet, so an
 * agent or a token is refused with agent_visibility_forbidden (invariant 18). A node the
 * actor's regions do not reach is unauthorized, and a node at another version than the caller read
 * is version_conflict, as is a route another command claimed between the read and the commit. A
 * route another node holds already is node_route_taken. A node that does not exist, one that
 * is archived, a site the installation does not have, a language the site does not publish in, a
 * node outside the site's tree, and a node that has a route on the site in that language already
 * are validation_failed: moving a route needs the redirects of PRD 5.9, which come with the
 * redirect manager.
 */
#[CommandName('node.set_route', version: 1)]
#[Experimental]
final readonly class SetNodeRoute implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public AggregateVersion $version,
        public SiteId $site,
        public Locale $locale,
        public RequestPath $route,
    ) {}

    /**
     * The node, at the version the caller read. The route is an aggregate the action reads too, so
     * the commit locks it and a route another command claimed meanwhile is version_conflict, but
     * the caller gives no version for it: a route that is taken already is node_route_taken, which
     * says what to do about it.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->node, $this->version));
    }
}
