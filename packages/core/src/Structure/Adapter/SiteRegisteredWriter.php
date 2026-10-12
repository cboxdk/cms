<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\SiteRegistered;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegistered as SiteRegisteredEvent;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegisteredV1;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes SiteRegistered in the commit (PRD 5.8, 5.9, 6.2 phase 7): the root node, a node of kind
 * site at the top of the tree at version 1, the site at the context's version, which is 1 for the
 * site the command read as absent, its locales, and in each locale the route `/` to the root node,
 * all at the changeset's time. It returns site.registered.
 *
 * It runs as the app role under the call's actor context, in the command transaction, after the
 * commit has locked the site's id and handle: `nodes_site_root` takes the root node and
 * `node_routes_site_root` the root routes only in the transaction of a site.register changeset by
 * the context's actor, and `sites_write` and `site_locales_write` take the site and its locales
 * only in the transaction of a site command's changeset by the context's actor whose audit row
 * names the site (the migration that adds the site locale commands).
 */
#[Internal]
final readonly class SiteRegisteredWriter implements MutationWriter
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
        return SiteRegistered::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof SiteRegistered) {
            throw new InvalidArgumentException(sprintf('The site registration writer writes SiteRegistered, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $site = $mutation->site->toString();
        $root = $mutation->root->toString();
        $at = Timestamps::of($context->at);

        $db->table('nodes')->insert([
            'id' => $root,
            'parent_id' => null,
            'kind' => NodeKind::Site->value,
            'path' => NodeCreated::label($mutation->root),
            'mount_source_id' => null,
            'lifecycle' => NodeLifecycle::Active->value,
            'version' => 1,
            'created_at' => $at,
        ]);

        $db->table('sites')->insert([
            'id' => $site,
            'handle' => $mutation->handle,
            'root_node_id' => $root,
            'version' => $context->version->value,
            'created_at' => $at,
        ]);

        $db->table('site_locales')->insert(array_map(static fn (Locale $locale): array => [
            'site_id' => $site,
            'locale' => $locale->value,
            'created_at' => $at,
            'retired_at' => null,
        ], $mutation->locales));

        $db->table('node_routes')->insert(array_map(static fn (Locale $locale): array => [
            'site_id' => $site,
            'locale' => $locale->value,
            'route' => '/',
            'node_id' => $root,
            'created_at' => $at,
        ], $mutation->locales));

        return [new SiteRegisteredEvent($context->version->value, new SiteRegisteredV1($mutation->site, $mutation->root, $mutation->locales))];
    }
}
