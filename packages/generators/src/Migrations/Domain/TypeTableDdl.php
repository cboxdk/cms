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
 * 80. Each foreign key has an index that leads with its column (the key leads with cms_entry_id),
 * and each filterable or sortable field an index on (cms_stage, cms_locale, <column>,
 * cms_entry_id), so a listing of one stage and locale filters and sorts on the index and pages
 * with the entry id as the last key (PRD 8.8).
 *
 * A column added to an existing table is nullable, so ADD COLUMN changes only the catalog; its
 * index is built with CREATE INDEX CONCURRENTLY outside a transaction, after DROP INDEX
 * CONCURRENTLY of an invalid one a failed build left (PRD 4.2).
 */
#[Internal]
final readonly class TypeTableDdl
{
    public const int FILLFACTOR = 80;

    /** The shared variant, the one variant of a type that is not localized (PRD 5.4). */
    public const string SHARED_VARIANT = 'shared';

    /** @var list<string> the system columns with a foreign key, each with its index */
    public const array REFERENCES = ['cms_home_node', 'cms_owner_actor'];

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
            ...array_map(static fn (LockedColumn $column): string => '    '.self::column($column).',', $lock->columnsOf(1)),
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

    private static function column(LockedColumn $column): string
    {
        $checks = array_map(static fn (string $check): string => "\n        check (".$check.')', $column->checks);

        return SqlText::identifier($column->name).' '.$column->type.($column->notNull ? ' not null' : '').implode('', $checks);
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
     * The CHECK of `cms_locale` for a type of the localization.
     */
    public static function localeCheck(Localization $localization): string
    {
        return match ($localization) {
            Localization::None => 'cms_locale = '.SqlText::literal(self::SHARED_VARIANT),
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
