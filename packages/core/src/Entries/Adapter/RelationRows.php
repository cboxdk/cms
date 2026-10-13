<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Entries\Boundary\RelationJson;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantEdges;
use Cbox\Cms\Core\Entries\Domain\RelationSortKey;
use Cbox\Cms\Core\Entries\Domain\RelationStage;
use Illuminate\Database\ConnectionInterface;

/**
 * Writes the relation edges of the variant heads (PRD 5.11) into `relations`, in the command
 * transaction, on the caller's connection and under its actor context, as the type rows are
 * written: the entry commands hand it the edges of each variant they write, in the stage they
 * write, and it never begins or ends a transaction.
 *
 * Each edge carries the attributes of its target (`meta`, as RelationJson writes them), the text
 * of its filterable attribute (`filter_key`) and, released, its relation's sort key (`sort_key`, as
 * RelationSortKey writes it) (PRD 5.11).
 *
 * replace() makes the edges of each variant in its stage what it is given, in one statement for
 * the whole run whatever the number of variants and targets (GUARDRAILS 4.1): an edge whose field
 * and ordinal the variant had keeps its row, or takes the new target, the others are inserted, and
 * the variant's edges in the stage that are not given any more are removed. removeStage() removes
 * a variant's edges of one stage, as an unrelease removes its released edges, remove() every edge
 * of a variant, as deleting a language's variant does, and removeEntries() every edge of the
 * entries, which a rebuild then writes again.
 */
#[Internal]
final readonly class RelationRows
{
    public const string TABLE = 'relations';

    private const string REPLACE = <<<'SQL'
        with k as (
            select * from unnest(?::uuid[], ?::text[], ?::text[]) as k(entry_id, variant, stage)
        ), e as (
            select * from unnest(?::uuid[], ?::text[], ?::text[], ?::text[], ?::integer[], ?::uuid[], ?::jsonb[], ?::text[], ?::text[]) as e(entry_id, variant, stage, field, ordinal, target_entry_id, meta, filter_key, sort_key)
        ), gone as (
            delete from relations as r using k
            where r.source_entry_id = k.entry_id and r.variant = k.variant and r.stage = k.stage
              and not exists (
                  select 1 from e
                  where e.entry_id = r.source_entry_id and e.variant = r.variant and e.stage = r.stage
                    and e.field = r.field and e.ordinal = r.ordinal
              )
        )
        insert into relations as r (source_entry_id, variant, stage, field, ordinal, target_entry_id, meta, filter_key, sort_key)
        select entry_id, variant, stage, field, ordinal, target_entry_id, meta, filter_key, sort_key from e
        on conflict (source_entry_id, variant, stage, field, ordinal)
            do update set target_entry_id = excluded.target_entry_id, meta = excluded.meta, filter_key = excluded.filter_key, sort_key = excluded.sort_key
            where (r.target_entry_id, r.meta, r.filter_key, r.sort_key) is distinct from (excluded.target_entry_id, excluded.meta, excluded.filter_key, excluded.sort_key)
        SQL;

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Replaces the edges of each variant in its stage, in one statement. A variant given twice in
     * one stage takes the edges given last.
     *
     * @param  list<VariantEdges>  $variants
     */
    public function replace(array $variants): void
    {
        /** @var array<string, VariantEdges> $byKey */
        $byKey = [];

        foreach ($variants as $variant) {
            $byKey[$variant->entry->toString().' '.$variant->variant->value.' '.$variant->stage->value] = $variant;
        }

        if ($byKey === []) {
            return;
        }

        $keys = [[], [], []];
        $edges = [[], [], [], [], [], []];
        $nullable = [[], [], []];

        foreach ($byKey as $variant) {
            $keys[0][] = $variant->entry->toString();
            $keys[1][] = $variant->variant->value;
            $keys[2][] = $variant->stage->value;

            foreach ($variant->edges as $edge) {
                $edges[0][] = $variant->entry->toString();
                $edges[1][] = $variant->variant->value;
                $edges[2][] = $variant->stage->value;
                $edges[3][] = $edge->field;
                $edges[4][] = (string) $edge->ordinal;
                $edges[5][] = $edge->target->toString();
                $nullable[0][] = RelationJson::meta($edge->meta);
                $nullable[1][] = $edge->filterKey;
                $nullable[2][] = $edge->sortKey instanceof FieldValue ? RelationSortKey::of($edge->sortKey) : null;
            }
        }

        $this->db->insert(self::REPLACE, [...array_map($this->literal(...), [...$keys, ...$edges]), ...array_map($this->nullableLiteral(...), $nullable)]);
    }

    /**
     * Removes the variant's edges of the stage.
     */
    public function removeStage(EntryId $entry, VariantKey $variant, RelationStage $stage): void
    {
        $this->db->table(self::TABLE)
            ->where('source_entry_id', $entry->toString())
            ->where('variant', $variant->value)
            ->where('stage', $stage->value)
            ->delete();
    }

    /**
     * Removes every edge of the variant, in both stages.
     */
    public function remove(EntryId $entry, VariantKey $variant): void
    {
        $this->db->table(self::TABLE)
            ->where('source_entry_id', $entry->toString())
            ->where('variant', $variant->value)
            ->delete();
    }

    /**
     * Removes every edge of the entries, in one statement.
     *
     * @param  non-empty-list<string>  $entries  the entries' ids
     */
    public function removeEntries(array $entries): void
    {
        $this->db->table(self::TABLE)->whereIn('source_entry_id', $entries)->delete();
    }

    /**
     * The values as a Postgres array literal, each quoted.
     *
     * @param  list<string>  $values
     */
    private function literal(array $values): string
    {
        return '{'.implode(',', array_map(self::quoted(...), $values)).'}';
    }

    /**
     * The values as a Postgres array literal, each quoted, and NULL for null.
     *
     * @param  list<?string>  $values
     */
    private function nullableLiteral(array $values): string
    {
        return '{'.implode(',', array_map(static fn (?string $value): string => $value === null ? 'NULL' : self::quoted($value), $values)).'}';
    }

    private static function quoted(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
