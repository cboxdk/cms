<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Override;

/**
 * A node gets its route on a site in one language (PRD 5.9): the path the public asks for, which
 * the longest-prefix lookup of a request resolves to this node, and below which the placements of
 * the node are addressed. The route is `/` or `/`-separated segments without a trailing slash, as
 * ROUTE_PATTERN says and the `node_routes` table checks.
 *
 * A route makes the node, and everything already live below it, reachable from the public internet,
 * so the mutation makes content public and an agent or a token may not plan it (invariant 18).
 */
#[Experimental]
final readonly class NodeRouteSet implements ChangesPublicVisibility
{
    /** `/` or `/`-separated segments of anything but slashes and white space, with no trailing slash. */
    public const string ROUTE_PATTERN = '/\A(?:\/|(?:\/[^\/\s]+)+)\z/u';

    /**
     * @throws InvalidMutation for anything else than a route
     */
    public function __construct(
        public NodeId $node,
        public SiteId $site,
        public Locale $locale,
        public string $route,
    ) {
        if (preg_match(self::ROUTE_PATTERN, $route) !== 1) {
            throw InvalidMutation::nodeRoute($node, $route);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }

    /**
     * A route always makes the node public: it is the address the public asks for.
     */
    #[Override]
    public function makesPublic(): bool
    {
        return true;
    }
}
