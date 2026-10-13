<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteMoved;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChangedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes NodeRouteMoved in the commit (PRD 5.9, 6.2 phase 7): the row of `node_routes` for the
 * site, the locale and the node set to the new route; the route the node had into
 * `node_route_history` for the site and the locale, pointing at the node, replacing a row of that
 * route that pointed at another node; the history row of the new route, when the node gets a route
 * it had before, so the live lookup answers it; and the node at the context's version. It returns
 * node.route_changed, as the first route of a node does.
 *
 * It runs as the app role under the call's actor context: `node_routes_change` and the policies of
 * `node_route_history` take the rows only for a node the actor's regions reach (PRD 5.9, 5.10),
 * and the routes' primary key keeps the new route unique behind the route's advisory lock.
 */
#[Internal]
final readonly class NodeRouteMovedWriter implements MutationWriter
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return NodeRouteMoved::class;
    }

    /**
     * @throws LogicException when the node does not have the route to move
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeRouteMoved) {
            throw new InvalidArgumentException(sprintf('The node route move writer writes NodeRouteMoved, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $site = $mutation->site->toString();
        $locale = $mutation->locale->value;
        $node = $mutation->node->toString();

        $moved = $db->table('node_routes')
            ->where('site_id', $site)
            ->where('locale', $locale)
            ->where('node_id', $node)
            ->where('route', $mutation->from)
            ->update(['route' => $mutation->to]);

        if ($moved !== 1) {
            throw new LogicException(sprintf('The node %s does not have the route "%s" on the site %s in %s, so it was not moved.', $node, $mutation->from, $site, $locale));
        }

        $db->table('node_route_history')->upsert([[
            'site_id' => $site,
            'locale' => $locale,
            'route' => $mutation->from,
            'node_id' => $node,
            'changeset_id' => $context->changesetId->toString(),
            'created_at' => Timestamps::of($context->at),
        ]], ['site_id', 'locale', 'route'], ['node_id', 'changeset_id', 'created_at']);
        $db->table('node_route_history')->where('site_id', $site)->where('locale', $locale)->where('route', $mutation->to)->delete();

        $changed = $db->table('nodes')->where('id', $node)->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The node %s is not a node the actor reaches, so its route was not moved.', $node));
        }

        return [new NodeRouteChanged($context->version->value, new NodeRouteChangedV1($mutation->node, $mutation->site, $mutation->locale))];
    }
}
