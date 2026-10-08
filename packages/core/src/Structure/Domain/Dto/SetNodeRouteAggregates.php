<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use Override;

/**
 * What node.set_route read (PRD 6.2 phase 1): the node, null when no node has the id; the site with
 * its locales, null when the installation has no such site; the route as an aggregate, which the
 * command expects free, with the node that holds it when one does; and the route the node has on
 * the site in the locale already, null when it has none. The kernel checks at commit that the node
 * and the site are still at the version read and the route still free, so a route another command
 * claimed meanwhile is version_conflict.
 */
#[Internal]
final readonly class SetNodeRouteAggregates implements Aggregates
{
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public SiteId $site,
        public ?StoredSite $storedSite,
        public Locale $locale,
        public NodeRouteRef $route,
        public ?NodeId $holder,
        public ?RequestPath $existing,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->node, $this->current?->version),
            new ReadVersion($this->site, $this->storedSite?->version),
            new ReadVersion($this->route, $this->holder instanceof NodeId ? AggregateVersion::first() : null),
        );
    }

    /**
     * The node, in the route's locale (PRD 5.10); anywhere when it read as absent or outside the
     * actor's regions.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->current instanceof StoredNode && $this->current->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->node, $this->locale))
            : AuthorizationScope::anywhere();
    }
}
