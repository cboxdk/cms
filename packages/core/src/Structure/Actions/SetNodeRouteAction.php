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
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Dto\SetNodeRouteAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Override;

/**
 * The write action of node.set_route (PRD 5.9, 6.2), exposed on every surface. resolve() reads the
 * node past the actor's regions, the site with its locales, the node that holds the route, past the
 * regions too, because a route resolves to one node whoever reads it, and the route the node has on
 * the site in that language already; refusals() refuses what those reads rule out; plan() sets the
 * route.
 *
 * A node that exists but is not reached by the actor's regions is unauthorized (PRD 5.10). A route
 * another node holds is node_route_taken. A node that is archived, a site the installation does not
 * have, a language the site does not publish in, a node that is not below the site's root, a mount,
 * and a node that has a route on the site in that language already are validation_failed: a route
 * that moves needs the redirects of PRD 5.9, which come with the redirect manager, so the kernel
 * refuses a change instead of breaking the URLs that are out there.
 *
 * The plan's NodeRouteSet makes content public, so the pipeline refuses the command from an agent
 * or a token with agent_visibility_forbidden (invariant 18).
 *
 * @implements WriteAction<SetNodeRoute, SetNodeRouteAggregates>
 * @implements RefusesCommand<SetNodeRoute, SetNodeRouteAggregates>
 */
#[Action(handles: SetNodeRoute::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class SetNodeRouteAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private NodeReader $nodes,
        private SiteDirectory $sites,
    ) {}

    /**
     * @param  SetNodeRoute  $command
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
     * @param  SetNodeRoute  $command
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

        if ($node instanceof StoredNode && $node->kind === NodeKind::Mount) {
            $refusals[] = $this->invalid('node', sprintf('The node %s is a mount, which is addressed through its source (PRD 5.8).', $command->node->toString()));
        }

        if (! $site instanceof StoredSite) {
            $refusals[] = $this->invalid('site', sprintf('No site %s exists.', $command->site->toString()));
        } elseif (! $site->publishes($command->locale)) {
            $refusals[] = $this->invalid('locale', sprintf('The site %s does not publish in %s.', $command->site->toString(), $command->locale->value));
        } elseif ($node instanceof StoredNode && $node->rootLabel() !== NodeCreated::label($site->root)) {
            $refusals[] = $this->invalid('node', sprintf('The node %s is not below the root of the site %s.', $command->node->toString(), $command->site->toString()));
        }

        if ($aggregates->existing instanceof RequestPath) {
            $refusals[] = $this->invalid('route', sprintf(
                'The node %s has the route "%s" on the site %s in %s already, and moving a route needs the redirects that keep the old URLs working (PRD 5.9), which the redirect manager brings.',
                $command->node->toString(),
                $aggregates->existing->value,
                $command->site->toString(),
                $command->locale->value,
            ));
        }

        $holder = $aggregates->holder;

        if ($holder instanceof NodeId) {
            $refusals[] = new CatalogError(ErrorCode::NodeRouteTaken, new FieldPath('route'), sprintf(
                'The node %s has the route "%s" on the site %s in %s.',
                $holder->toString(),
                $command->route->value,
                $command->site->toString(),
                $command->locale->value,
            ));
        }

        return $refusals;
    }

    /**
     * @param  SetNodeRoute  $command
     * @param  SetNodeRouteAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new NodeRouteSet($command->node, $command->site, $command->locale, $command->route->value));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
