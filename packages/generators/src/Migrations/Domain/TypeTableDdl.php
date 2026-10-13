<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\SqlText;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;

/**
 * The DDL of a type table, from its schema lock (PRD 4.1, 4.2, 11.6).
 *
 * The table has the system columns `cms_entry_id` (the entry, a foreign key to `entries`),
 * `cms_locale` (the shared variant, `shared`, for a type that is not localized), `cms_stage`
 * (`released`, and for a type with stages also `draft` and `staged`), `cms_home_node` (a foreign
 * key to `nodes`) and `cms_owner_actor` (a foreign key to `actors`, null unless an actor owns the
 * entry), which the row level security of every type table reads (the core's TypeTableAccess), and
 * the key (cms_entry_id, cms_locale, cms_stage). Then one column per top-level field, sorted by
 * name, with the type, NOT NULL and CHECK constraints of its descriptor. The table has fillfactor
 * 80. A field's column that references a kernel table, the column of a to-one relation, has a
 * foreign key to that table's `id` ON DELETE RESTRICT (PRD 5.11, 11.6), named as its index. Each
 * foreign key has an index that leads with its column (the key leads with cms_entry_id), and each
 * filterable or sortable field an index on (cms_stage, cms_locale, <column>, cms_entry_id), so a
 * listing of one stage and locale filters and sorts on the index and pages with the entry id as
 * the last key (PRD 8.8).
 *
 * A relation whose targets carry attributes (PRD 5.11) has, for each attribute declared
 * filterable, a partial index on the kernel's `relations` table: `<table>__<column>__<attribute>`
 * on (target_entry_id, filter_key, sort_key, source_entry_id) for the released edges of the field,
 * where filter_key is the edge's text of the attribute, so a filter on the target and the
 * attribute, ordered by the sort key, reads the index alone. down() drops those indexes before it drops the table or the columns, because they
 * are not the table's.
 *
 * A column added to an existing table is nullable, so ADD COLUMN changes only the catalog; its
 * index is built with CREATE INDEX CONCURRENTLY outside a transaction, after DROP INDEX
 * CONCURRENTLY of an invalid one a failed build left (PRD 4.2). Its foreign key is added NOT VALID,
 * which takes no scan of the table, after dropping one a failed run left, and then validated,
 * which scans the table without blocking its writes.
 */
#[Internal]
final readonly class TypeTableDdl
{
    public const int FILLFACTOR = 80;

    /** The shared variant, the one variant of a type that is not localized (PRD 5.4). */
    public const string SHARED_VARIANT = 'shared';

    /** @var list<string> the system columns with a foreign key, each with its index */
    public const array REFERENCES = ['cms_home_node', 'cms_owner_actor'];

    /** The kernel's table of relation edges (PRD 5.11). */
    public const string RELATIONS = 'relations';

    /**
     * The statements of step 1: the table and its indexes.
     *
     * @return list<string>
     */
    public static function create(TypeTableLock $lock): array
    {
        $table = SqlText::identifier($lock->table);
        $lines = [
            '    cms_entry_id uuid not null references entries (id),',
            "    cms_locale text not null\n        check (".self::localeCheck($lock->localization).'),',
            "    cms_stage text not null\n        check (".self::stageCheck($lock->stages).'),',
            '    cms_home_node uuid not null references nodes (id),',
            '    cms_owner_actor uuid references actors (id),',
            ...array_map(static fn (LockedColumn $column): string => '    '.self::column($column, $lock->table).',', $lock->columnsOf(1)),
            '    primary key (cms_entry_id, cms_locale, cms_stage)',
        ];

        $indexes = array_map(
            static fn (string $column): string => sprintf('create index %s on %s (%s)', SqlText::identifier(TypeTableNames::index($lock->table, $column)), $table, $column),
            self::REFERENCES,
        );

        foreach ($lock->columnsOf(1) as $column) {
            if ($column->indexed) {
                $indexes[] = 'create index '.self::fieldIndex($lock->table, $column);
            }

            if ($column->references !== null) {
                $indexes[] = 'create index '.self::referenceIndex($lock->table, $column);
            }

            foreach ($column->relationIndexes as $attribute) {
                $indexes[] = 'create index '.self::relationIndex($lock->table, $column, $attribute);
            }
        }

        return [
            "create table {$table} (\n".implode("\n", $lines)."\n) with (fillfactor = ".self::FILLFACTOR.')',
            ...$indexes,
        ];
    }

    /**
     * The statements of a later step: one ALTER TABLE that adds its columns, then for each indexed
     * one DROP INDEX CONCURRENTLY IF EXISTS and CREATE INDEX CONCURRENTLY.
     *
     * @return list<string>
     */
    public static function add(TypeTableLock $lock, int $step): array
    {
        $columns = $lock->columnsOf($step);
        $statements = [sprintf(
            "alter table %s\n%s",
            SqlText::identifier($lock->table),
            implode(",\n", array_map(static fn (LockedColumn $column): string => '    add column if not exists '.self::column($column), $columns)),
        )];

        foreach ($columns as $column) {
            if ($column->indexed) {
                $statements[] = 'drop index concurrently if exists '.SqlText::identifier(TypeTableNames::index($lock->table, $column->name));
                $statements[] = 'create index concurrently '.self::fieldIndex($lock->table, $column);
            }

            if ($column->references !== null) {
                $table = SqlText::identifier($lock->table);
                $name = SqlText::identifier(TypeTableNames::index($lock->table, $column->name));
                $statements[] = 'drop index concurrently if exists '.$name;
                $statements[] = 'create index concurrently '.self::referenceIndex($lock->table, $column);
                $statements[] = sprintf('alter table %s drop constraint if exists %s', $table, $name);
                $statements[] = sprintf(
                    "alter table %s\n    add constraint %s foreign key (%s) %s not valid",
                    $table,
                    $name,
                    SqlText::identifier($column->name),
                    self::reference($column->references),
                );
                $statements[] = sprintf('alter table %s validate constraint %s', $table, $name);
            }

            foreach ($column->relationIndexes as $attribute) {
                $statements[] = 'drop index concurrently if exists '.SqlText::identifier(self::relationIndexName($lock->table, $column, $attribute));
                $statements[] = 'create index concurrently '.self::relationIndex($lock->table, $column, $attribute);
            }
        }

        return $statements;
    }

    /**
     * The statements that drop the partial indexes on `relations` of the step's columns, which
     * dropping the table or its columns leaves; none when the step has no relation index.
     *
     * @return list<string>
     */
    public static function dropRelationIndexes(TypeTableLock $lock, int $step): array
    {
        $statements = [];

        foreach ($lock->columnsOf($step) as $column) {
            foreach ($column->relationIndexes as $attribute) {
                $statements[] = 'drop index if exists '.SqlText::identifier(self::relationIndexName($lock->table, $column, $attribute));
            }
        }

        return $statements;
    }

    /**
     * The statement that undoes step 1.
     */
    public static function dropTable(TypeTableLock $lock): string
    {
        return 'drop table '.SqlText::identifier($lock->table);
    }

    /**
     * The statement that undoes a later step: it drops the step's columns, and their indexes with
     * them.
     */
    public static function dropColumns(TypeTableLock $lock, int $step): string
    {
        return sprintf(
            "alter table %s\n%s",
            SqlText::identifier($lock->table),
            implode(",\n", array_map(static fn (LockedColumn $column): string => '    drop column if exists '.SqlText::identifier($column->name), $lock->columnsOf($step))),
        );
    }

    /**
     * A column of the table; in step 1, given the table, with its foreign key, named as its index,
     * which a later step adds apart.
     */
    private static function column(LockedColumn $column, ?string $table = null): string
    {
        $checks = array_map(static fn (string $check): string => "\n        check (".$check.')', $column->checks);
        $reference = $table !== null && $column->references !== null
            ? sprintf("\n        constraint %s %s", SqlText::identifier(TypeTableNames::index($table, $column->name)), self::reference($column->references))
            : '';

        return SqlText::identifier($column->name).' '.$column->type.($column->notNull ? ' not null' : '').$reference.implode('', $checks);
    }

    /**
     * `references <table> (id) on delete restrict`: a referenced entry is never deleted while a
     * row names it (PRD 5.11).
     */
    private static function reference(string $table): string
    {
        return 'references '.SqlText::identifier($table).' (id) on delete restrict';
    }

    /**
     * `<name> on <table> (<column>)`: the index of a foreign key, which leads with its column.
     */
    private static function referenceIndex(string $table, LockedColumn $column): string
    {
        return sprintf(
            '%s on %s (%s)',
            SqlText::identifier(TypeTableNames::index($table, $column->name)),
            SqlText::identifier($table),
            SqlText::identifier($column->name),
        );
    }

    /**
     * `<name> on relations (target_entry_id, filter_key, sort_key, source_entry_id) where field =
     * '<address>' and stage = 'released'`: the partial index of a relation's filterable attribute
     * (PRD 5.11), whose text each edge of the field keeps as its filter key.
     */
    private static function relationIndex(string $table, LockedColumn $column, string $attribute): string
    {
        return sprintf(
            "%s on %s (target_entry_id, filter_key, sort_key, source_entry_id)\n    where field = %s and stage = 'released'",
            SqlText::identifier(self::relationIndexName($table, $column, $attribute)),
            self::RELATIONS,
            SqlText::literal(self::address($column->name)),
        );
    }

    /**
     * `<table>__<column>__<attribute>`, shortened as every index name is.
     */
    private static function relationIndexName(string $table, LockedColumn $column, string $attribute): string
    {
        return TypeTableNames::index($table, $column->name.TypeTableNames::INDEX_SEPARATOR.$attribute);
    }

    /**
     * How the relations table names the field of a column: its handle, or `ext.<namespace>.<handle>`
     * for the column `ext__<namespace>__<handle>` of an extension field.
     */
    private static function address(string $column): string
    {
        $parts = explode(TypeTableNames::INDEX_SEPARATOR, $column);

        return count($parts) === 3 ? implode('.', $parts) : $column;
    }

    /**
     * `<name> on <table> (cms_stage, cms_locale, <column>, cms_entry_id)`.
     */
    private static function fieldIndex(string $table, LockedColumn $column): string
    {
        return sprintf(
            '%s on %s (cms_stage, cms_locale, %s, cms_entry_id)',
            SqlText::identifier(TypeTableNames::index($table, $column->name)),
            SqlText::identifier($table),
            SqlText::identifier($column->name),
        );
    }

    /**
     * The CHECK of `cms_locale` for a type of the localization (PRD 10.1, 11.6): the shared variant
     * alone for a type without localization, and a language per row, never the shared variant, for
     * a type with localization variants, whose shared fields are in each language's row.
     */
    public static function localeCheck(Localization $localization): string
    {
        return match ($localization) {
            Localization::None => 'cms_locale = '.SqlText::literal(self::SHARED_VARIANT),
            Localization::Variants => 'cms_locale <> '.SqlText::literal(self::SHARED_VARIANT),
        };
    }

    /**
     * The CHECK of `cms_stage` for a type of the stages.
     */
    public static function stageCheck(Stages $stages): string
    {
        return match ($stages) {
            Stages::None => "cms_stage = 'released'",
            Stages::DraftRelease => "cms_stage in ('released', 'draft', 'staged')",
        };
    }
}
