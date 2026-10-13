<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A node's route on a site in one language moves from one path to another (PRD 5.9): the route the
 * node had, `from`, goes to the route history as a prefix redirect, so every URL below it answers
 * 301 below the new route, `to`, in one hop, and the placements below the node keep what they are.
 * Both are `/` or `/`-separated segments without a trailing slash, as NodeRouteSet::ROUTE_PATTERN
 * says. The node was reachable from the public internet at the old route already, so a move makes
 * nothing public that was not, and an agent may plan it; a node without a route gets one with
 * NodeRouteSet, which an agent may not plan (invariant 18).
 */
#[Experimental]
final readonly class NodeRouteMoved implements Mutation
{
    /**
     * @throws InvalidMutation for anything else than two different routes
     */
    public function __construct(
        public NodeId $node,
        public SiteId $site,
        public Locale $locale,
        public string $from,
        public string $to,
    ) {
        foreach ([$from, $to] as $route) {
            if (preg_match(NodeRouteSet::ROUTE_PATTERN, $route) !== 1) {
                throw InvalidMutation::nodeRoute($node, $route);
            }
        }

        if ($from === $to) {
            throw InvalidMutation::nodeRouteWithoutChange($node, $to);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }
}
