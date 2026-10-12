<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleRemoved;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemoved as SiteLocaleRemovedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemovedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes SiteLocaleRemoved in the commit (PRD 5.9, 6.2 phase 7): the locale's row in
 * `site_locales` retired at the changeset's time, which keeps the routes in the locale with it, and
 * the site's row at the context's version. It returns site.locale_removed.
 *
 * It runs as the app role under the call's actor context: `site_locales_update` and `sites_update`
 * take the rows only in the transaction of a site command's changeset by the context's actor whose
 * audit row names the site, so the writer changes the site the command was authorized on and no
 * other.
 */
#[Internal]
final readonly class SiteLocaleRemovedWriter implements MutationWriter
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
        return SiteLocaleRemoved::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof SiteLocaleRemoved) {
            throw new InvalidArgumentException(sprintf('The site locale writer writes SiteLocaleRemoved, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $site = $mutation->site->toString();

        $retired = $db->table('site_locales')
            ->where('site_id', $site)
            ->where('locale', $mutation->locale->value)
            ->whereNull('retired_at')
            ->update(['retired_at' => Timestamps::of($context->at)]);

        if ($retired !== 1) {
            throw new LogicException(sprintf('The site %s does not publish in %s, or is not one this changeset names, so the locale was not retired.', $site, $mutation->locale->value));
        }

        $changed = $db->table('sites')->where('id', $site)->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The site %s is not one this changeset names, so its locale was not retired.', $site));
        }

        return [new SiteLocaleRemovedEvent($context->version->value, new SiteLocaleRemovedV1($mutation->site, $mutation->root, $mutation->locale))];
    }
}
