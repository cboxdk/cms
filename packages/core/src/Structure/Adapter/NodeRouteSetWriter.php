<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
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
 * Writes NodeRouteSet in the commit (PRD 5.9, 6.2 phase 7): the row in `node_routes` for the site,
 * the locale and the route, pointing at the node, at the changeset's time, and the node's row set
 * to the context's version, because the node is the aggregate the route belongs to. It returns
 * node.route_changed.
 *
 * It runs as the app role under the call's actor context: `node_routes_write` takes the route only
 * for a node the actor's regions reach, and the foreign key to `site_locales` keeps the locale one
 * the site publishes in (PRD 5.9, 5.10).
 */
#[Internal]
final readonly class NodeRouteSetWriter implements MutationWriter
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
        return NodeRouteSet::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeRouteSet) {
            throw new InvalidArgumentException(sprintf('The node route writer writes NodeRouteSet, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);

        $db->table('node_routes')->insert([
            'site_id' => $mutation->site->toString(),
            'locale' => $mutation->locale->value,
            'route' => $mutation->route,
            'node_id' => $mutation->node->toString(),
            'created_at' => Timestamps::of($context->at),
        ]);

        $changed = $db->table('nodes')->where('id', $mutation->node->toString())->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The node %s is not a node the actor reaches, so its route was not set.', $mutation->node->toString()));
        }

        return [new NodeRouteChanged($context->version->value, new NodeRouteChangedV1($mutation->node, $mutation->site, $mutation->locale))];
    }
}
