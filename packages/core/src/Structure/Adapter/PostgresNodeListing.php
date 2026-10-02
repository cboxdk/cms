<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\ListedNode;
use Cbox\Cms\Core\Structure\Domain\NodeListing;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * NodeListing on Postgres (PRD 5.8, 5.10), on the default connection, or the one named, inside the
 * read transaction and under its actor context, on the write PDO. A path label names the nodes
 * above the ones the context reaches, so a page is one statement through the owner function
 * cms_access_node_list, which gives only the nodes the context's regions reach.
 */
#[Internal]
final readonly class PostgresNodeListing implements NodeListing
{
    /** A page of the nodes the context reaches, as the owner role. */
    public const string NODES = 'select id, parent_id, kind, site_id, site_handle, label from cms_access_node_list(?::uuid, ?)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function reached(?NodeId $after, int $limit): array
    {
        $nodes = [];

        foreach ($this->db()->select(self::NODES, [$after?->toString(), $limit], false) as $row) {
            $row = GrantRows::row($row);
            $parent = GrantRows::text($row, 'parent_id', nullable: true);
            $site = GrantRows::text($row, 'site_id', nullable: true);
            $handle = GrantRows::text($row, 'site_handle', nullable: true);
            $nodes[] = new ListedNode(
                NodeId::fromString(GrantRows::text($row, 'id')),
                $parent === null ? null : NodeId::fromString($parent),
                NodeKind::from(GrantRows::text($row, 'kind')),
                $site === null ? null : SiteId::fromString($site),
                $handle === null ? null : new SiteHandle($handle),
                GrantRows::text($row, 'label'),
            );
        }

        return $nodes;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
