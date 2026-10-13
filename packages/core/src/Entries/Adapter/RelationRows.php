<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantEdges;
use Cbox\Cms\Core\Entries\Domain\RelationStage;
use Illuminate\Database\ConnectionInterface;

/**
 * Writes the relation edges of the variant heads (PRD 5.11) into `relations`, in the command
 * transaction, on the caller's connection and under its actor context, as the type rows are
 * written: the entry commands hand it the edges of each variant they write, in the stage they
 * write, and it never begins or ends a transaction.
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
            select * from unnest(?::uuid[], ?::text[], ?::text[], ?::text[], ?::integer[], ?::uuid[]) as e(entry_id, variant, stage, field, ordinal, target_entry_id)
        ), gone as (
            delete from relations as r using k
            where r.source_entry_id = k.entry_id and r.variant = k.variant and r.stage = k.stage
              and not exists (
                  select 1 from e
                  where e.entry_id = r.source_entry_id and e.variant = r.variant and e.stage = r.stage
                    and e.field = r.field and e.ordinal = r.ordinal
              )
        )
        insert into relations as r (source_entry_id, variant, stage, field, ordinal, target_entry_id)
        select entry_id, variant, stage, field, ordinal, target_entry_id from e
        on conflict (source_entry_id, variant, stage, field, ordinal)
            do update set target_entry_id = excluded.target_entry_id
            where r.target_entry_id is distinct from excluded.target_entry_id
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
            }
        }

        $this->db->insert(self::REPLACE, array_map($this->literal(...), [...$keys, ...$edges]));
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
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
    }
}
