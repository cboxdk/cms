<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TypeId;

/**
 * The schema lock of a type table (PRD 11.6, 11.12): what the committed migrations of the table
 * build, written by cms:generate next to them as `<table>.lock` and committed with them.
 *
 * The lock is the table's history in steps. Step 1 creates the table with the columns of step 1;
 * each later step adds the columns of that step. cms:generate compares a type's descriptor with its
 * lock and adds a step for the fields that are new, so the migrations follow from the schema and the
 * committed locks alone, and every migration can be written again from its lock, byte for byte.
 */
#[Internal]
final readonly class TypeTableLock
{
    /** The version of the lock's form. */
    public const int FORMAT = 1;

    /** The extension of a lock file, which the migrator's `*_*.php` never matches. */
    public const string EXTENSION = 'lock';

    /**
     * @param  string  $table  `<owner>__<handle>` (TypeName::table())
     * @param  int  $steps  the number of steps, from 1; each has at least one column but the first
     * @param  list<LockedColumn>  $columns  sorted by step, then by name, each name once
     */
    public function __construct(
        public string $table,
        public TypeName $type,
        public TypeId $typeId,
        public Stages $stages,
        public Localization $localization,
        public int $steps,
        public array $columns,
    ) {}

    /**
     * The name of the lock's file in the migrations directory.
     */
    public function file(): string
    {
        return $this->table.'.'.self::EXTENSION;
    }

    /**
     * The columns the step adds, sorted by name.
     *
     * @return list<LockedColumn>
     */
    public function columnsOf(int $step): array
    {
        return array_values(array_filter($this->columns, static fn (LockedColumn $column): bool => $column->step === $step));
    }

    public function column(string $name): ?LockedColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }
}
