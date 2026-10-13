<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantFields;
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
 * is by key, so a save costs the same whatever else the table holds (GUARDRAILS 4.1), and
 * writeAll() and releaseAll() write a run of variants of one type in the same statements.
 *
 * release() writes the released row of a draft-release type from the revision a release makes
 * public (PRD 5.6). A variant without a draft row has a draft equal to its released row, so before
 * the released row changes, its values are kept as the draft row; then the released row takes the
 * revision's values, and the draft row is removed again when it holds the same values, so it
 * exists after the release exactly when the pending draft differs from what was released.
 * unrelease() removes the released row again when the content is unpublished (PRD 6.4), keeping
 * its values as the draft row where the variant had none, and remove() removes every row of a
 * language's variant when it is deleted (PRD 10.3).
 *
 * A type without localization has the one variant `shared`, whose row is `cms_locale = 'shared'`.
 * A type with localization variants (PRD 10.1) has a row per language and none for the shared
 * variant: the language's row holds its translatable fields and the shared fields, denormalized
 * into each language's row, so a query on one language never joins the shared variant. The caller
 * therefore hands a language's variant the fields of the whole row, its own and the shared
 * variant's (LocaleRow::of()), and the shared variant its own fields alone: a write of the shared
 * variant changes the shared columns of every row of the entry at the stage, in statements by
 * entry whatever the number of languages, and makes no row, because a language's row exists once
 * that language's variant was written. A release of the shared variant changes the shared columns
 * of the entry's released rows, and an unrelease of it removes them, so a language's released row
 * exists while both its variant and the shared one are released.
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

    /** The most rows one upsert carries, well below Postgres' 65535 bindings for up to 200 fields and the system columns. */
    public const int ROWS_PER_STATEMENT = 250;

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @throws LogicException when the entry is not there to take its home node from, or the type does not hold the variant
     */
    public function write(TypeDefinition $type, EntryId $entry, VariantKey $variant, FieldValues $fields): void
    {
        $this->writeAll($type, [new VariantFields($entry, $variant, $fields)]);
    }

    /**
     * write() for a run of variants of one type, in one statement per step.
     *
     * @param  non-empty-list<VariantFields>  $variants
     *
     * @throws LogicException when an entry is not there to take its home node from, or the type does not hold a variant
     */
    public function writeAll(TypeDefinition $type, array $variants): void
    {
        $stage = $type->capabilities->stages === Stages::None ? self::RELEASED : self::DRAFT;
        $table = $type->name->table();
        [$rows, $shared] = $this->split($type, $variants);

        if ($rows !== []) {
            $columns = $this->upsert($type, $table, $stage, $rows);

            if ($stage === self::DRAFT) {
                $this->dropDraftLikeReleased($table, $columns, $this->byVariants($rows));
            }
        }

        if ($shared !== []) {
            $entries = $this->entriesOf($shared);

            if ($stage === self::DRAFT) {
                $this->keepDraft($table, $this->copied($type), $this->byEntries($entries));
            }

            $this->updateShared($type, $table, $stage, $shared);

            if ($stage === self::DRAFT) {
                $this->dropDraftLikeReleased($table, $this->fieldColumns($type), $this->byEntries($entries));
            }
        }
    }

    /**
     * Writes the released row of a draft-release type from the fields of the revision released,
     * keeping the pending draft as its own row only where it differs.
     *
     * @throws LogicException when the type has no stages to release, the entry is not there to take its home node from, or the type does not hold the variant
     */
    public function release(TypeDefinition $type, EntryId $entry, VariantKey $variant, FieldValues $fields): void
    {
        $this->releaseAll($type, [new VariantFields($entry, $variant, $fields)]);
    }

    /**
     * release() for a run of variants of one type, in one statement per step.
     *
     * @param  non-empty-list<VariantFields>  $variants
     *
     * @throws LogicException when the type has no stages to release, an entry is not there to take its home node from, or the type does not hold a variant
     */
    public function releaseAll(TypeDefinition $type, array $variants): void
    {
        if ($type->capabilities->stages !== Stages::DraftRelease) {
            throw new LogicException(sprintf('The type %s has stages %s, so no revision of it is released; the kernel refuses such a release before the commit.', $type->name->value, $type->capabilities->stages->value));
        }

        $table = $type->name->table();
        $copied = $this->copied($type);
        [$rows, $shared] = $this->split($type, $variants);

        if ($rows !== []) {
            $this->keepDraft($table, $copied, $this->byVariants($rows));
            $columns = $this->upsert($type, $table, self::RELEASED, $rows);
            $this->dropDraftLikeReleased($table, $columns, $this->byVariants($rows));
        }

        if ($shared !== []) {
            $entries = $this->entriesOf($shared);
            $this->keepDraft($table, $copied, $this->byEntries($entries));
            $this->updateShared($type, $table, self::RELEASED, $shared);
            $this->dropDraftLikeReleased($table, $this->fieldColumns($type), $this->byEntries($entries));
        }
    }

    /**
     * Removes the released row of a draft-release type when its content is unpublished (PRD 6.4).
     * A variant without a draft row has a draft equal to its released row, so the released row's
     * values are kept as the draft row first; the draft row then holds the pending draft either way.
     * For the shared variant of a type with localization variants, every language's released row
     * of the entry goes.
     *
     * @throws LogicException when the type has no stages to release, or the type does not hold the variant
     */
    public function unrelease(TypeDefinition $type, EntryId $entry, VariantKey $variant): void
    {
        if ($type->capabilities->stages !== Stages::DraftRelease) {
            throw new LogicException(sprintf('The type %s has stages %s, so no revision of it is released or unreleased.', $type->name->value, $type->capabilities->stages->value));
        }

        $this->assertHeld($type, $variant);
        $table = $type->name->table();
        $localizedShared = $variant->isShared() && $type->capabilities->localization === Localization::Variants;
        $key = $localizedShared
            ? $this->byEntries([$entry->toString()])
            : $this->byVariants([new VariantFields($entry, $variant, new FieldValues)]);

        $this->keepDraft($table, $this->copied($type), $key);
        $delete = $this->db->table($table)
            ->where(self::ENTRY, $entry->toString())
            ->where(self::STAGE, self::RELEASED);

        if (! $localizedShared) {
            $delete->where(self::LOCALE, $variant->value);
        }

        $delete->delete();
    }

    /**
     * Removes every row of a language's variant, released and draft, when the variant is deleted
     * (PRD 10.3), in one statement by the entry and the locale. The entry's other languages keep
     * their rows.
     *
     * @throws LogicException when the variant is the shared one, whose fields are in every language's row, or the type does not hold it
     */
    public function remove(TypeDefinition $type, EntryId $entry, VariantKey $variant): void
    {
        if ($variant->isShared()) {
            throw new LogicException(sprintf('The shared variant of the entry %s has no rows of its own to remove; a language\'s variant is removed.', $entry->toString()));
        }

        $this->assertHeld($type, $variant);
        $this->db->table($type->name->table())
            ->where(self::ENTRY, $entry->toString())
            ->where(self::LOCALE, $variant->value)
            ->delete();
    }

    /**
     * The variants that are rows of the table, and the shared variants of a type with
     * localization variants, whose fields go into the rows of the entry's languages.
     *
     * @param  non-empty-list<VariantFields>  $variants
     * @return array{list<VariantFields>, list<VariantFields>}
     *
     * @throws LogicException when the type does not hold a variant
     */
    private function split(TypeDefinition $type, array $variants): array
    {
        $rows = [];
        $shared = [];

        foreach ($variants as $variant) {
            $this->assertHeld($type, $variant->variant);

            if ($variant->variant->isShared() && $type->capabilities->localization === Localization::Variants) {
                $shared[] = $variant;
            } else {
                $rows[] = $variant;
            }
        }

        return [$rows, $shared];
    }

    /**
     * @throws LogicException when an entry of the type has no such variant
     */
    private function assertHeld(TypeDefinition $type, VariantKey $variant): void
    {
        if ($type->holds($variant)) {
            return;
        }

        throw new LogicException(sprintf(
            'The type %s has localization %s, so its one variant is the shared one, and its table has no row of the variant %s.',
            $type->name->value,
            $type->capabilities->localization->value,
            $variant->value,
        ));
    }

    /**
     * The columns a draft row takes from the released row: the keys, the home node, the owner and
     * every top-level field's column.
     *
     * @return list<string>
     *
     * @throws LogicException when a top-level field has no column
     */
    private function copied(TypeDefinition $type): array
    {
        return [self::ENTRY, self::LOCALE, self::HOME, self::OWNER, ...$this->fieldColumns($type)];
    }

    /**
     * Every top-level field's column.
     *
     * @return list<string>
     *
     * @throws LogicException when a top-level field has no column
     */
    private function fieldColumns(TypeDefinition $type): array
    {
        $columns = [];

        foreach ($type->fields as $field) {
            $columns[] = $field->column->name ?? throw new LogicException(sprintf('The top-level field "%s" of %s has no column.', $field->address(), $type->name->value));
        }

        return $columns;
    }

    /**
     * Copies the released row of each matched row to its draft row when it has none, in one
     * statement.
     *
     * @param  list<string>  $copied  the columns the draft row takes from the released row
     * @param  array{string, string, list<string>}  $key  the rows matched: the unnest of the keys, the match on it, and the bindings
     */
    private function keepDraft(string $table, array $copied, array $key): void
    {
        [$unnest, $match, $bindings] = $key;

        $this->db->insert(
            sprintf(
                'insert into "%1$s" (%2$s, %3$s) select %4$s, ? from "%1$s" as t join %5$s on %6$s where t.%3$s = ? on conflict (%7$s, %8$s, %3$s) do nothing',
                $table,
                implode(', ', array_map($this->quoted(...), $copied)),
                self::STAGE,
                implode(', ', array_map(static fn (string $column): string => 't."'.$column.'"', $copied)),
                $unnest,
                $match,
                self::ENTRY,
                self::LOCALE,
            ),
            [self::DRAFT, ...$bindings, self::RELEASED],
        );
    }

    /**
     * Writes one row of each variant in the stage, with its entry's home node and owning actor.
     * Returns the field columns.
     *
     * @param  non-empty-list<VariantFields>  $variants
     * @return list<string>
     *
     * @throws LogicException when an entry is not there to take its home node from
     */
    private function upsert(TypeDefinition $type, string $table, string $stage, array $variants): array
    {
        $identities = $this->identities($variants);
        $rows = [];
        $fieldColumns = [];

        foreach ($variants as $variant) {
            [$home, $owner] = $identities[$variant->entry->toString()] ?? throw new LogicException(sprintf('The entry %s has no row to write its type row with.', $variant->entry->toString()));
            $columns = StoredContent::columns($type, $variant->fields);
            $fieldColumns = array_keys($columns);
            $rows[] = [
                self::ENTRY => $variant->entry->toString(),
                self::LOCALE => $variant->variant->value,
                self::STAGE => $stage,
                self::HOME => $home,
                self::OWNER => $owner,
                ...$columns,
            ];
        }

        foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->db->table($table)->upsert(
                $chunk,
                [self::ENTRY, self::LOCALE, self::STAGE],
                [self::HOME, self::OWNER, ...$fieldColumns],
            );
        }

        return $fieldColumns;
    }

    /**
     * Sets the shared variant's columns on every row of each entry at the stage, in one statement
     * per ROWS_PER_STATEMENT entries: the rows are matched by entry, so the statements are the
     * same whatever the number of languages (GUARDRAILS 4.1).
     *
     * @param  non-empty-list<VariantFields>  $shared  the shared variant of each entry, each entry once
     */
    private function updateShared(TypeDefinition $type, string $table, string $stage, array $shared): void
    {
        $fields = $type->fieldsOf(VariantKey::shared());

        if ($fields === []) {
            return;
        }

        /** @var list<ColumnDefinition> $columns */
        $columns = array_values(array_filter(array_map(
            static fn (FieldDefinition $field): ?ColumnDefinition => $field->column,
            $fields,
        )));

        foreach (array_chunk($shared, self::ROWS_PER_STATEMENT) as $chunk) {
            $values = [];

            foreach ($chunk as $variant) {
                $values[] = StoredContent::columns($type, $variant->fields);
            }

            $sets = [];
            $named = [];
            $bindings = [$this->entriesLiteral($this->entriesOf($chunk))];

            foreach ($columns as $column) {
                $sets[] = sprintf('"%1$s" = v."%1$s"::%2$s', $column->name, $column->type);
                $named[] = '"'.$column->name.'"';
                $bindings[] = $this->textsLiteral(array_map(static fn (array $row): string|int|null => $row[$column->name] ?? null, $values));
            }

            $this->db->update(
                sprintf(
                    'update "%1$s" as r set %2$s from unnest(?::uuid[], %3$s) as v(entry_id, %4$s) where r.%5$s = v.entry_id and r.%6$s = ?',
                    $table,
                    implode(', ', $sets),
                    implode(', ', array_fill(0, count($columns), '?::text[]')),
                    implode(', ', $named),
                    self::ENTRY,
                    self::STAGE,
                ),
                [...$bindings, $stage],
            );
        }
    }

    /**
     * The home node and owning actor of each entry of the variants, by entry id.
     *
     * @param  non-empty-list<VariantFields>  $variants
     * @return array<string, array{string, ?string}>
     *
     * @throws LogicException when an entry's row cannot be read
     */
    private function identities(array $variants): array
    {
        $identities = [];

        foreach ($this->db->table('entries')
            ->whereIn('id', $this->entriesOf($variants))
            ->useWritePdo()
            ->get(['id', 'home_node_id', 'owner_actor_id']) as $identity) {
            $id = property_exists($identity, 'id') ? $identity->id : null;
            $home = property_exists($identity, 'home_node_id') ? $identity->home_node_id : null;
            $owner = property_exists($identity, 'owner_actor_id') ? $identity->owner_actor_id : null;

            if (! is_string($id) || ! is_string($home) || ($owner !== null && ! is_string($owner))) {
                throw new LogicException('An entry has no readable row to write its type row with.');
            }

            $identities[$id] = [$home, $owner];
        }

        return $identities;
    }

    private function quoted(string $column): string
    {
        return '"'.$column.'"';
    }

    /**
     * The entries of the variants, each once, in order.
     *
     * @param  non-empty-list<VariantFields>  $variants
     * @return non-empty-list<string>
     */
    private function entriesOf(array $variants): array
    {
        return array_values(array_unique(array_map(static fn (VariantFields $variant): string => $variant->entry->toString(), $variants)));
    }

    /**
     * The rows of the variants, matched by entry and locale: the unnest of the keys as `k`, the
     * match of a row `t` on it, and the bindings.
     *
     * @param  non-empty-list<VariantFields>  $variants
     * @return array{string, string, list<string>}
     */
    private function byVariants(array $variants): array
    {
        return [
            'unnest(?::uuid[], ?::text[]) as k(entry_id, locale)',
            sprintf('t.%s = k.entry_id and t.%s = k.locale', self::ENTRY, self::LOCALE),
            [
                $this->entriesLiteral(array_map(static fn (VariantFields $variant): string => $variant->entry->toString(), $variants)),
                $this->textsLiteral(array_map(static fn (VariantFields $variant): string => $variant->variant->value, $variants)),
            ],
        ];
    }

    /**
     * Every row of the entries, matched by entry: the unnest of the keys as `k`, the match of a
     * row `t` on it, and the bindings.
     *
     * @param  non-empty-list<string>  $entries
     * @return array{string, string, list<string>}
     */
    private function byEntries(array $entries): array
    {
        return [
            'unnest(?::uuid[]) as k(entry_id)',
            sprintf('t.%s = k.entry_id', self::ENTRY),
            [$this->entriesLiteral($entries)],
        ];
    }

    /**
     * @param  list<string>  $entries
     */
    private function entriesLiteral(array $entries): string
    {
        return '{'.implode(',', $entries).'}';
    }

    /**
     * The values as a Postgres text array literal, each quoted, null as NULL.
     *
     * @param  list<string|int|null>  $values
     */
    private function textsLiteral(array $values): string
    {
        return '{'.implode(',', array_map(
            static fn (string|int|null $value): string => $value === null ? 'NULL' : '"'.addcslashes((string) $value, '"\\').'"',
            $values,
        )).'}';
    }

    /**
     * Removes each matched variant's draft row when its released row holds the same value in
     * every field's column.
     *
     * @param  list<string>  $columns
     * @param  array{string, string, list<string>}  $key  the draft rows matched: the unnest of the keys, the match on it, and the bindings
     */
    private function dropDraftLikeReleased(string $table, array $columns, array $key): void
    {
        [$unnest, $match, $bindings] = $key;
        $same = $columns === [] ? '' : sprintf(
            ' and row(%s) is not distinct from row(%s)',
            implode(', ', array_map(static fn (string $column): string => 't."'.$column.'"', $columns)),
            implode(', ', array_map(static fn (string $column): string => 'r."'.$column.'"', $columns)),
        );

        $this->db->delete(
            sprintf(
                'delete from "%1$s" as t using "%1$s" as r, %2$s where %3$s and t.%4$s = ? and r.%5$s = t.%5$s and r.%6$s = t.%6$s and r.%4$s = ?%7$s',
                $table,
                $unnest,
                $match,
                self::STAGE,
                self::ENTRY,
                self::LOCALE,
                $same,
            ),
            [...$bindings, self::DRAFT, self::RELEASED],
        );
    }
}
