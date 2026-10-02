<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The SiteDirectory on Postgres: one site by its key, through the lookups `cms_structure_site` and
 * `cms_structure_site_named` (SECURITY DEFINER as the owner role, see the migration that adds
 * site.register), which read without an actor context, so cms:sites:sync reads before any command
 * runs. Every context reads `sites` and `site_locales` anyway (`sites_read`, `site_locales_read`);
 * the lookups give no more than that, one site at a time. It reads on the write PDO, inside the
 * caller's transaction when one is open, on the default connection or the one named.
 */
#[Internal]
final readonly class PostgresSiteDirectory implements SiteDirectory
{
    public const string BY_ID = 'select id::text as id, handle, root_node_id::text as root_node_id, version, locales from cms_structure_site(?::uuid)';

    public const string BY_HANDLE = 'select id::text as id, handle, root_node_id::text as root_node_id, version, locales from cms_structure_site_named(?)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function find(SiteId $site): ?StoredSite
    {
        return $this->one(self::BY_ID, $site->toString());
    }

    #[Override]
    public function named(SiteHandle $handle): ?StoredSite
    {
        return $this->one(self::BY_HANDLE, $handle->value);
    }

    private function one(string $query, string $key): ?StoredSite
    {
        $rows = $this->connections->connection($this->connection)->select($query, [$key], false);

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $id = is_object($row) && property_exists($row, 'id') ? $row->id : null;
        $handle = is_object($row) && property_exists($row, 'handle') ? $row->handle : null;
        $root = is_object($row) && property_exists($row, 'root_node_id') ? $row->root_node_id : null;
        $version = is_object($row) && property_exists($row, 'version') ? $row->version : null;
        $locales = is_object($row) && property_exists($row, 'locales') ? $row->locales : null;

        if (! is_string($id) || ! is_string($handle) || ! is_string($root) || ! is_int($version) || ! is_string($locales)) {
            throw new UnexpectedValueException(sprintf(
                'A site has a text id, handle, root node and locales and an integer version, got %s, %s, %s, %s and %s.',
                get_debug_type($id),
                get_debug_type($handle),
                get_debug_type($root),
                get_debug_type($locales),
                get_debug_type($version),
            ));
        }

        return new StoredSite(
            SiteId::fromString($id),
            new SiteHandle($handle),
            NodeId::fromString($root),
            new AggregateVersion($version),
            $locales === '' ? [] : array_map(static fn (string $tag): Locale => new Locale($tag), explode(',', $locales)),
        );
    }
}
