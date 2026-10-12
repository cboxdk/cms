<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryHead;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPlacement;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPlacementLocale;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Cbox\Cms\Core\Placements\Adapter\PlacementRows;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * EntryListing on Postgres (PRD 5.4, 5.7, 5.10, 8.8), as the app role on the default connection,
 * or the one named, inside the read transaction and under its actor context, on the write PDO.
 *
 * Every statement carries the explicit access predicate `cms_access_home(home_node_id,
 * owner_actor_id)`: the entry is read when the context's actor owns it or one of its regions
 * reaches its home node, the same test as the entries table's own policy, so row level security
 * is the backstop and not the primary filter (PRD 5.10). An entry the context sees only because
 * it is released somewhere live is not listed. find() is three statements: the entry, its heads
 * with the number of each draft, highest and released revision, the schema version and payload of the draft, from
 * `revisions` and `revision_payloads` for a type with full history and from `head_snapshots`
 * otherwise, and its placements with their locales, those the context reaches. page() is one:
 * the entries newest first by id, the keyset key, with the shared head's release state and the
 * last change, the newest of the entry's creation, its revisions, its snapshots and its release
 * log, each read by the entry's own index. A subtree filter joins `nodes` and tests the home's
 * path against the node's with `<@`; a node the context does not reach has no path for it, so
 * nothing is listed below it.
 */
#[Internal]
final readonly class PostgresEntryListing implements EntryListing
{
    /** The entry, when the context reaches its home or owns it. */
    public const string ENTRY = <<<'SQL'
        select e.type_id::text as type_id, e.home_node_id::text as home_node_id, e.lifecycle, e.version
        from entries as e
        where e.id = ?::uuid and cms_access_home(e.home_node_id, e.owner_actor_id)
        SQL;

    /** Every head of the entry, the shared variant first, each with its numbers and its draft's payload. */
    public const string HEADS = <<<'SQL'
        select
            h.variant, h.version, h.release_state,
            r.rev_no as draft_number, s.rev_no as snapshot_number, p.rev_no as released_number,
            (select max(l.rev_no) from revisions as l where l.entry_id = h.entry_id and l.variant = h.variant) as latest_number,
            coalesce(r.schema_version, s.schema_version) as content_schema_version,
            coalesce(rp.content, s.content)::text as content
        from variant_heads as h
        left join revisions as r on r.revision_id = h.draft_revision_id
        left join revision_payloads as rp on rp.revision_id = r.revision_id and rp.kind = r.kind
        left join revisions as p on p.revision_id = h.published_revision_id and h.release_state = 'released'
        left join head_snapshots as s on s.entry_id = h.entry_id and s.variant = h.variant
        where h.entry_id = ?::uuid
        order by (h.variant <> 'shared'), h.variant
        SQL;

    /** The released stage of every placement of the entry the context reaches, a row per locale. */
    public const string PLACEMENTS = <<<'SQL'
        select p.id::text as id, p.version, g.node_id::text as node_id, pl.locale, pl.slug, pl.visibility, pl.live_from, pl.live_until, pl.canonical
        from placements as p
        join placement_generations as g on g.placement_id = p.id and g.stage = 'released'
        left join placement_locales as pl on pl.placement_id = p.id and pl.stage = 'released'
        where p.entry_id = ?::uuid
        order by p.id, pl.locale
        SQL;

    /** The columns of a page, with the last change over the entry's own rows. */
    public const string PAGE_COLUMNS = <<<'SQL'
        select
            e.id::text as id, e.type_id::text as type_id, e.home_node_id::text as home_node_id, e.lifecycle, e.version, h.release_state,
            greatest(
                e.created_at,
                coalesce((select max(r.created_at) from revisions as r where r.entry_id = e.id), e.created_at),
                coalesce((select max(s.updated_at) from head_snapshots as s where s.entry_id = e.id), e.created_at),
                coalesce((select max(l.effective_at) from release_log as l where l.entry_id = e.id), e.created_at)
            ) as changed_at
        from entries as e
        left join variant_heads as h on h.entry_id = e.id and h.variant = 'shared'
        SQL;

    /** The explicit access predicate (PRD 5.10): the context's actor owns the entry, or a region reaches its home. */
    public const string REACHED = 'cms_access_home(e.home_node_id, e.owner_actor_id)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private TypeCatalog $types,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function find(EntryId $entry): ?EntryDetail
    {
        $db = $this->db();
        $rows = $db->select(self::ENTRY, [$entry->toString()], false);

        if ($rows === []) {
            return null;
        }

        $row = PlacementRows::object($rows[0]);
        $type = TypeId::fromString(PlacementRows::text($row, 'type_id'));
        $definition = $this->types->find($type);

        return new EntryDetail(
            $entry,
            $type,
            NodeId::fromString(PlacementRows::text($row, 'home_node_id')),
            EntryLifecycle::from(PlacementRows::text($row, 'lifecycle')),
            new AggregateVersion(PlacementRows::integer($row, 'version')),
            $this->heads($db, $entry, $definition),
            $this->placements($db, $entry),
        );
    }

    #[Override]
    public function page(ListEntries $query, int $limit): array
    {
        $sql = self::PAGE_COLUMNS;
        $bindings = [];
        $where = [self::REACHED];

        if ($query->node instanceof NodeId) {
            $sql .= "\njoin nodes as n on n.id = e.home_node_id";
            $where[] = 'n.path <@ (select t.path from nodes as t where t.id = ?::uuid)';
            $bindings[] = $query->node->toString();
        }

        if ($query->type instanceof TypeId) {
            $where[] = 'e.type_id = ?::uuid';
            $bindings[] = $query->type->toString();
        }

        if ($query->state instanceof ReleaseState) {
            $where[] = 'h.release_state = ?';
            $bindings[] = $query->state->value;
        }

        if ($query->after instanceof EntryId) {
            $where[] = 'e.id < ?::uuid';
            $bindings[] = $query->after->toString();
        }

        $bindings[] = max($limit, 0);
        $sql .= "\nwhere ".implode(' and ', $where)."\norder by e.id desc\nlimit ?";
        $entries = [];

        foreach ($this->db()->select($sql, $bindings, false) as $row) {
            $row = PlacementRows::object($row);
            $state = PlacementRows::textOrNull($row, 'release_state');
            $entries[] = new ListedEntry(
                EntryId::fromString(PlacementRows::text($row, 'id')),
                TypeId::fromString(PlacementRows::text($row, 'type_id')),
                NodeId::fromString(PlacementRows::text($row, 'home_node_id')),
                EntryLifecycle::from(PlacementRows::text($row, 'lifecycle')),
                $state === null ? null : ReleaseState::from($state),
                PlacementRows::instant(PlacementRows::text($row, 'changed_at')) ?? throw new UnexpectedValueException('An entry has a last change.'),
                new AggregateVersion(PlacementRows::integer($row, 'version')),
            );
        }

        return $entries;
    }

    /**
     * @return list<EntryHead>
     */
    private function heads(ConnectionInterface $db, EntryId $entry, ?TypeDefinition $type): array
    {
        $heads = [];

        foreach ($db->select(self::HEADS, [$entry->toString()], false) as $row) {
            $row = PlacementRows::object($row);
            $revision = $this->integerOrNull($row, 'draft_number') ?? $this->integerOrNull($row, 'snapshot_number');
            $released = $this->integerOrNull($row, 'released_number');

            if ($revision === null) {
                throw new UnexpectedValueException(sprintf('The head of the variant %s of the entry %s has no current revision; every head is written with its first revision.', PlacementRows::text($row, 'variant'), $entry->toString()));
            }

            $content = PlacementRows::textOrNull($row, 'content');
            $schemaVersion = PlacementRows::integer($row, 'content_schema_version');
            $heads[] = new EntryHead(
                VariantKey::fromString(PlacementRows::text($row, 'variant')),
                new AggregateVersion(PlacementRows::integer($row, 'version')),
                new RevisionNumber($revision),
                new RevisionNumber($this->integerOrNull($row, 'latest_number') ?? $revision),
                $released === null ? null : new RevisionNumber($released),
                ReleaseState::from(PlacementRows::text($row, 'release_state')),
                $schemaVersion,
                $type instanceof TypeDefinition && $content !== null && $schemaVersion === $type->version
                    ? StoredContent::fields($type, $content)
                    : new FieldValues,
            );
        }

        return $heads;
    }

    /**
     * @return list<EntryPlacement>
     */
    private function placements(ConnectionInterface $db, EntryId $entry): array
    {
        /** @var array<string, array{NodeId, AggregateVersion, list<EntryPlacementLocale>}> $placements */
        $placements = [];

        foreach ($db->select(self::PLACEMENTS, [$entry->toString()], false) as $row) {
            $row = PlacementRows::object($row);
            $id = PlacementRows::text($row, 'id');
            $placements[$id] ??= [NodeId::fromString(PlacementRows::text($row, 'node_id')), new AggregateVersion(PlacementRows::integer($row, 'version')), []];

            if (PlacementRows::textOrNull($row, 'locale') !== null) {
                $placements[$id][2][] = new EntryPlacementLocale(
                    new Locale(PlacementRows::text($row, 'locale')),
                    new Slug(PlacementRows::text($row, 'slug')),
                    PlacementRows::visibility($row),
                    PlacementRows::window($row),
                    PlacementRows::boolean($row, 'canonical'),
                );
            }
        }

        $listed = [];

        foreach ($placements as $id => [$node, $version, $locales]) {
            $listed[] = new EntryPlacement(PlacementId::fromString($id), $node, $version, $locales);
        }

        return $listed;
    }

    private function integerOrNull(object $row, string $column): ?int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value !== null && ! is_int($value)) {
            throw new UnexpectedValueException(sprintf('The column %s of an entry is an integer, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
