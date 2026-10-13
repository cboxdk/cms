<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\PreviewSource;
use Cbox\Cms\Core\Entries\Domain\EntryPreviews;
use Cbox\Cms\Core\Placements\Adapter\PlacementRows;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * EntryPreviews on Postgres (PRD 5.4, 5.6, 5.10), as the app role on the default connection, or
 * the one named, inside the read transaction and under its actor context, on the write PDO.
 *
 * It is one statement, PREVIEW: the entry, when the context's actor owns it or a region reaches its
 * home (the explicit access predicate of PRD 5.10, with row level security as the backstop), its
 * head of the variant with the numbers of its draft and released revision and its release state,
 * and the revision asked for, the draft when none is: for a type with full history the revision by
 * its unique key (entry_id, variant, rev_no) joined to its payload by (revision_id, kind), and for
 * a type whose history is audit-only or none the head snapshot when its number is the one asked
 * for. It reads no placement and no other variant, so it is the same statement whatever the entry
 * holds besides (GUARDRAILS 4.1). The payload is read with StoredContent when the revision's schema
 * version is the type's.
 */
#[Internal]
final readonly class PostgresEntryPreviews implements EntryPreviews
{
    /**
     * The entry's head of the variant and the revision asked for: bindings variant, revision,
     * revision and entry, a null revision for the draft.
     */
    public const string PREVIEW = <<<'SQL'
        select
            e.type_id::text as type_id, e.home_node_id::text as home_node_id, h.release_state,
            coalesce(r.rev_no, s.rev_no) as draft_number, p.rev_no as released_number,
            x.rev_no as revision_number, x.schema_version, x.content
        from entries as e
        join variant_heads as h on h.entry_id = e.id and h.variant = ?
        left join revisions as r on r.revision_id = h.draft_revision_id
        left join revisions as p on p.revision_id = h.published_revision_id and h.release_state = 'released'
        left join head_snapshots as s on s.entry_id = e.id and s.variant = h.variant
        left join lateral (
            select v.rev_no, v.schema_version, vp.content::text as content
            from revisions as v
            join revision_payloads as vp on vp.revision_id = v.revision_id and vp.kind = v.kind
            where v.entry_id = e.id and v.variant = h.variant and v.rev_no = coalesce(?::integer, r.rev_no)
            union all
            select s.rev_no, s.schema_version, s.content::text
            where r.revision_id is null and s.rev_no = coalesce(?::integer, s.rev_no)
            limit 1
        ) as x on true
        where e.id = ?::uuid and cms_access_home(e.home_node_id, e.owner_actor_id)
        SQL;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private TypeCatalog $types,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function find(EntryId $entry, VariantKey $variant, ?RevisionNumber $revision): ?PreviewSource
    {
        $rows = $this->connections->connection($this->connection)->select(
            self::PREVIEW,
            [$variant->value, $revision?->value, $revision?->value, $entry->toString()],
            false,
        );

        if ($rows === []) {
            return null;
        }

        $row = PlacementRows::object($rows[0]);
        $type = TypeId::fromString(PlacementRows::text($row, 'type_id'));
        $draft = new RevisionNumber(PlacementRows::integer($row, 'draft_number'));
        $released = $this->number($row, 'released_number');
        $found = $this->number($row, 'revision_number');
        $definition = $this->types->find($type);
        $content = PlacementRows::textOrNull($row, 'content');
        $schemaVersion = $this->integer($row, 'schema_version');

        return new PreviewSource(
            $type,
            NodeId::fromString(PlacementRows::text($row, 'home_node_id')),
            $draft,
            $released,
            ReleaseState::from(PlacementRows::text($row, 'release_state')),
            $found ?? $revision ?? $draft,
            $found instanceof RevisionNumber,
            $definition instanceof TypeDefinition && $content !== null && $schemaVersion === $definition->version
                ? StoredContent::fields($definition, $content)
                : null,
        );
    }

    private function number(object $row, string $column): ?RevisionNumber
    {
        $value = $this->integer($row, $column);

        return $value === null ? null : new RevisionNumber($value);
    }

    private function integer(object $row, string $column): ?int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return $value === null ? null : PlacementRows::integerValue($value, $column);
    }
}
