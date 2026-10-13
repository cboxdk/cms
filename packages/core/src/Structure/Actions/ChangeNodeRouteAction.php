<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteMoved;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ChangeNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Dto\SetNodeRouteAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Override;

/**
 * The write action of node.set_route version 2 (PRD 5.9, 6.2), exposed on every surface. It reads
 * what version 1 reads (SetNodeRouteAction): the node past the actor's regions, the site with its
 * locales, the node that holds the route, and the route the node has on the site in that language
 * already. It refuses what version 1 refuses, except a node that has a route already: for such a
 * node plan() moves the route with NodeRouteMoved, which records the route the node had as a
 * prefix redirect, and for a node without one it sets the route with NodeRouteSet, as version 1
 * does. The route the node has already is validation_failed, because nothing would change.
 *
 * A move makes nothing public that was not, because the node was reachable at the old route, so an
 * agent may move a route; the first route of a node makes it public, and the pipeline refuses that
 * from an agent or a token with agent_visibility_forbidden (invariant 18).
 *
 * @implements WriteAction<ChangeNodeRoute, SetNodeRouteAggregates>
 * @implements RefusesCommand<ChangeNodeRoute, SetNodeRouteAggregates>
 */
#[Action(handles: ChangeNodeRoute::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ChangeNodeRouteAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private NodeReader $nodes,
        private SiteDirectory $sites,
    ) {}

    /**
     * @param  ChangeNodeRoute  $command
     */
    #[Override]
    public function resolve(Command $command): SetNodeRouteAggregates
    {
        return new SetNodeRouteAggregates(
            $command->node,
            $this->nodes->node($command->node),
            $command->site,
            $this->sites->find($command->site),
            $command->locale,
            new NodeRouteRef($command->site, $command->locale, $command->route),
            $this->nodes->routeHolder($command->site, $command->locale, $command->route),
            $this->nodes->routeOf($command->site, $command->locale, $command->node),
        );
    }

    /**
     * @param  ChangeNodeRoute  $command
     * @param  SetNodeRouteAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $node = $aggregates->current;

        if ($node instanceof StoredNode && ! $node->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('node'), sprintf(
                'The node %s is not reached by the actor\'s grants, and a route is set with the rights on its node (PRD 5.10).',
                $command->node->toString(),
            ))];
        }

        $refusals = [];
        $site = $aggregates->storedSite;

        if ($node instanceof StoredNode && $node->archived()) {
            $refusals[] = $this->invalid('node', sprintf('The node %s is archived, and archived structure takes no route (PRD 6.4).', $command->node->toString()));
        }

        if (! $site instanceof StoredSite) {
            $refusals[] = $this->invalid('site', sprintf('No site %s exists.', $command->site->toString()));
        } elseif (! $site->publishes($command->locale)) {
            $refusals[] = $this->invalid('locale', sprintf('The site %s does not publish in %s.', $command->site->toString(), $command->locale->value));
        } elseif ($node instanceof StoredNode && $node->rootLabel() !== NodeCreated::label($site->root)) {
            $refusals[] = $this->invalid('node', sprintf('The node %s is not below the root of the site %s.', $command->node->toString(), $command->site->toString()));
        }

        if ($aggregates->existing instanceof RequestPath && $aggregates->existing->value === $command->route->value) {
            $refusals[] = $this->invalid('route', sprintf(
                'The node %s has the route "%s" on the site %s in %s already.',
                $command->node->toString(),
                $command->route->value,
                $command->site->toString(),
                $command->locale->value,
            ));
        } elseif ($aggregates->holder instanceof NodeId) {
            $refusals[] = new CatalogError(ErrorCode::NodeRouteTaken, new FieldPath('route'), sprintf(
                'The node %s has the route "%s" on the site %s in %s.',
                $aggregates->holder->toString(),
                $command->route->value,
                $command->site->toString(),
                $command->locale->value,
            ));
        }

        return $refusals;
    }

    /**
     * @param  ChangeNodeRoute  $command
     * @param  SetNodeRouteAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $existing = $aggregates->existing;

        return new Plan($existing instanceof RequestPath
            ? new NodeRouteMoved($command->node, $command->site, $command->locale, $existing->value, $command->route->value)
            : new NodeRouteSet($command->node, $command->site, $command->locale, $command->route->value));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
