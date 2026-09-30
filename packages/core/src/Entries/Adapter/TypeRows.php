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
 *
 * release() writes the released row of a draft-release type from the revision a release makes
 * public (PRD 5.6). A variant without a draft row has a draft equal to its released row, so before
 * the released row changes, its values are kept as the draft row; then the released row takes the
 * revision's values, and the draft row is removed again when it holds the same values, so it
 * exists after the release exactly when the pending draft differs from what was released.
 * unrelease() removes the released row again when the content is unpublished (PRD 6.4), keeping
 * its values as the draft row where the variant had none.
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
        $stage = $type->capabilities->stages === Stages::None ? self::RELEASED : self::DRAFT;
        $columns = StoredContent::columns($type, $fields);
        $table = $type->name->table();

        $this->upsert($table, $stage, $entry, $variant, $columns);

        if ($stage === self::DRAFT) {
            $this->dropDraftLikeReleased($table, array_keys($columns), $entry, $variant);
        }
    }

    /**
     * Writes the released row of a draft-release type from the fields of the revision released,
     * keeping the pending draft as its own row only where it differs.
     *
     * @throws LogicException when the type has no stages to release, or the entry is not there to take its home node from
     */
    public function release(TypeDefinition $type, EntryId $entry, VariantKey $variant, FieldValues $fields): void
    {
        if ($type->capabilities->stages !== Stages::DraftRelease) {
            throw new LogicException(sprintf('The type %s has stages %s, so no revision of it is released; the kernel refuses such a release before the commit.', $type->name->value, $type->capabilities->stages->value));
        }

        $columns = StoredContent::columns($type, $fields);
        $table = $type->name->table();
        $copied = [self::ENTRY, self::LOCALE, self::HOME, self::OWNER, ...array_keys($columns)];

        $this->keepDraft($table, $copied, $entry, $variant);

        $this->upsert($table, self::RELEASED, $entry, $variant, $columns);
        $this->dropDraftLikeReleased($table, array_keys($columns), $entry, $variant);
    }

    /**
     * Removes the released row of a draft-release type when its content is unpublished (PRD 6.4).
     * A variant without a draft row has a draft equal to its released row, so the released row's
     * values are kept as the draft row first; the draft row then holds the pending draft either way.
     *
     * @throws LogicException when the type has no stages to release
     */
    public function unrelease(TypeDefinition $type, EntryId $entry, VariantKey $variant): void
    {
        if ($type->capabilities->stages !== Stages::DraftRelease) {
            throw new LogicException(sprintf('The type %s has stages %s, so no revision of it is released or unreleased.', $type->name->value, $type->capabilities->stages->value));
        }

        $table = $type->name->table();
        $columns = [];

        foreach ($type->fields as $field) {
            $columns[] = $field->column->name ?? throw new LogicException(sprintf('The top-level field "%s" of %s has no column.', $field->address(), $type->name->value));
        }

        $this->keepDraft($table, [self::ENTRY, self::LOCALE, self::HOME, self::OWNER, ...$columns], $entry, $variant);
        $this->db->table($table)
            ->where(self::ENTRY, $entry->toString())
            ->where(self::LOCALE, $variant->value)
            ->where(self::STAGE, self::RELEASED)
            ->delete();
    }

    /**
     * Copies the released row of the variant to its draft row when it has none.
     *
     * @param  list<string>  $copied  the columns the draft row takes from the released row
     */
    private function keepDraft(string $table, array $copied, EntryId $entry, VariantKey $variant): void
    {
        $this->db->insert(
            sprintf(
                'insert into "%1$s" (%2$s, %3$s) select %4$s, ? from "%1$s" where %5$s = ? and %6$s = ? and %7$s = ? on conflict (%5$s, %6$s, %7$s) do nothing',
                $table,
                implode(', ', array_map($this->quoted(...), $copied)),
                self::STAGE,
                implode(', ', array_map($this->quoted(...), $copied)),
                self::ENTRY,
                self::LOCALE,
                self::STAGE,
            ),
            [self::DRAFT, $entry->toString(), $variant->value, self::RELEASED],
        );
    }

    /**
     * Writes one row of the variant in the stage, with the entry's home node and owning actor.
     *
     * @param  array<string, string|int|null>  $columns
     *
     * @throws LogicException when the entry is not there to take its home node from
     */
    private function upsert(string $table, string $stage, EntryId $entry, VariantKey $variant, array $columns): void
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
    }

    private function quoted(string $column): string
    {
        return '"'.$column.'"';
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
