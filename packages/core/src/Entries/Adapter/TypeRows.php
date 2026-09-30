<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Writes the current state of a variant into its type's table (PRD 4.1, 11.6), in the command
 * transaction, from the type as the TypeCatalog gives it: the table `<owner>__<handle>` and a
 * column per top-level field, never a type the kernel knows by name (GUARDRAILS 2.4).
 *
 * The row's key is (cms_entry_id, cms_locale, cms_stage). A type with stages none has only the
 * released stage, so a save writes its released row. A type whose stages are draft-release writes
 * its draft row, and then removes it again when it holds the same values as the released row, so a
 * draft row exists only where a pending draft differs (PRD 4.1). The row takes the entry's home
 * node and owning actor, which row level security tests (PRD 5.10), from `entries`. Every statement
 * is by key, so a save costs the same whatever else the table holds (GUARDRAILS 4.1).
 */
#[Internal]
final readonly class TypeRows
{
    public const string ENTRY = 'cms_entry_id';

    public const string LOCALE = 'cms_locale';

    public const string STAGE = 'cms_stage';

    public const string HOME = 'cms_home_node';

    public const string OWNER = 'cms_owner_actor';

    public const string RELEASED = 'released';

    public const string DRAFT = 'draft';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @throws LogicException when the entry is not there to take its home node from
     */
    public function write(TypeDefinition $type, EntryId $entry, VariantKey $variant, FieldValues $fields): void
    {
        $identity = $this->db->table('entries')
            ->where('id', $entry->toString())
            ->useWritePdo()
            ->first(['home_node_id', 'owner_actor_id']);

        $home = $identity !== null && property_exists($identity, 'home_node_id') ? $identity->home_node_id : null;
        $owner = $identity !== null && property_exists($identity, 'owner_actor_id') ? $identity->owner_actor_id : null;

        if (! is_string($home) || ($owner !== null && ! is_string($owner))) {
            throw new LogicException(sprintf('The entry %s has no row to write its type row with.', $entry->toString()));
        }

        $stage = $type->capabilities->stages === Stages::None ? self::RELEASED : self::DRAFT;
        $columns = StoredContent::columns($type, $fields);
        $table = $type->name->table();

        $this->db->table($table)->upsert(
            [[
                self::ENTRY => $entry->toString(),
                self::LOCALE => $variant->value,
                self::STAGE => $stage,
                self::HOME => $home,
                self::OWNER => $owner,
                ...$columns,
            ]],
            [self::ENTRY, self::LOCALE, self::STAGE],
            [self::HOME, self::OWNER, ...array_keys($columns)],
        );

        if ($stage === self::DRAFT) {
            $this->dropDraftLikeReleased($table, array_keys($columns), $entry, $variant);
        }
    }

    /**
     * Removes the draft row when the released row holds the same value in every field's column.
     *
     * @param  list<string>  $columns
     */
    private function dropDraftLikeReleased(string $table, array $columns, EntryId $entry, VariantKey $variant): void
    {
        $same = $columns === [] ? '' : sprintf(
            ' and row(%s) is not distinct from row(%s)',
            implode(', ', array_map(static fn (string $column): string => 'd."'.$column.'"', $columns)),
            implode(', ', array_map(static fn (string $column): string => 'r."'.$column.'"', $columns)),
        );

        $this->db->delete(
            sprintf(
                'delete from "%1$s" as d using "%1$s" as r where d.%2$s = ? and d.%3$s = ? and d.%4$s = ? and r.%2$s = d.%2$s and r.%3$s = d.%3$s and r.%4$s = ?%5$s',
                $table,
                self::ENTRY,
                self::LOCALE,
                self::STAGE,
                $same,
            ),
            [$entry->toString(), $variant->value, self::DRAFT, self::RELEASED],
        );
    }
}
