<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleAdded;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleAdded as SiteLocaleAddedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleAddedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes SiteLocaleAdded in the commit (PRD 5.9, 6.2 phase 7): the locale's row in `site_locales`
 * at the changeset's time, or its retired row back, the route `/` to the root node in the locale
 * when the site has none there, and the site's row at the context's version. It returns
 * site.locale_added.
 *
 * It runs as the app role under the call's actor context: `site_locales_write`,
 * `site_locales_update`, `node_routes_site_root` and `sites_update` take the rows only in the
 * transaction of a site command's changeset by the context's actor whose audit row names the site,
 * so the writer changes the site the command was authorized on and no other.
 */
#[Internal]
final readonly class SiteLocaleAddedWriter implements MutationWriter
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
        return SiteLocaleAdded::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof SiteLocaleAdded) {
            throw new InvalidArgumentException(sprintf('The site locale writer writes SiteLocaleAdded, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $site = $mutation->site->toString();
        $at = Timestamps::of($context->at);

        $reinstated = $db->table('site_locales')
            ->where('site_id', $site)
            ->where('locale', $mutation->locale->value)
            ->whereNotNull('retired_at')
            ->update(['retired_at' => null]);

        if ($reinstated === 0) {
            $db->table('site_locales')->insert([
                'site_id' => $site,
                'locale' => $mutation->locale->value,
                'created_at' => $at,
                'retired_at' => null,
            ]);
        }

        $db->table('node_routes')->insertOrIgnore([
            'site_id' => $site,
            'locale' => $mutation->locale->value,
            'route' => '/',
            'node_id' => $mutation->root->toString(),
            'created_at' => $at,
        ]);

        $changed = $db->table('sites')->where('id', $site)->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The site %s is not one this changeset names, so its locale was not added.', $site));
        }

        return [new SiteLocaleAddedEvent($context->version->value, new SiteLocaleAddedV1($mutation->site, $mutation->root, $mutation->locale))];
    }
}
