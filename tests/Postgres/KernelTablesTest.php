<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Phpstan\KernelTables;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * PRD 6.5 invariants 1 and 13: the testkit's KernelTableWriteRule reports a write by name to a
 * kernel table outside the kernel, and it knows the tables from KernelTables::NAMES. This holds
 * the list equal to the tables and LIST partitions the migrations built, so a new core table is
 * never left out of the rule. The partition manager's partitions, `<table>_p<digits>`, are covered
 * by the rule through their table. The workbench's generated type tables, `<owner>__<handle>`
 * (PRD 11.6), are the application's, not the kernel's: they are exactly the tables of the types
 * in the generated TypeCatalog, and no kernel table has a double underscore in its name.
 */

/**
 * The tables Laravel and the packages the core registers make, which the kernel does not own:
 * Laravel's migration log and cboxdk/laravel-operations' operations.
 */
const NOT_KERNEL_TABLES = ['migrations', 'operations'];

it('lists every table and LIST partition the migrations built, and nothing else', function (): void {
    $tables = [];

    foreach (DB::connection('pgsql_owner')->select(
        <<<'SQL'
            select c.relname::text as name
            from pg_class c
            where c.relkind in ('r', 'p')
              and c.relnamespace = any (select oid from pg_namespace where nspname = any (current_schemas(false)))
              and c.relname !~ '_p[0-9]+$'
            order by 1
            SQL,
    ) as $row) {
        $name = is_object($row) && property_exists($row, 'name') ? $row->name : null;
        $tables[] = is_string($name) ? $name : throw new LogicException('Expected a table name.');
    }

    $typeTables = array_map(static fn (TypeDefinition $type): string => $type->name->table(), app(TypeCatalog::class)->all());
    sort($typeTables);

    $kernelTables = array_values(array_diff($tables, NOT_KERNEL_TABLES, $typeTables));

    expect($kernelTables)->toBe(KernelTables::NAMES)
        ->and(array_map(static fn (string $table): ?string => KernelTables::of(sprintf(' public."%s" ', strtoupper($table))), $kernelTables))->toBe($kernelTables)
        ->and(array_values(array_intersect(NOT_KERNEL_TABLES, $tables)))->toBe(NOT_KERNEL_TABLES)
        ->and(array_values(array_intersect($tables, $typeTables)))->toBe($typeTables)
        ->and($typeTables)->not->toBe([])
        ->and(array_filter(KernelTables::NAMES, static fn (string $table): bool => str_contains($table, TypeName::TABLE_SEPARATOR)))->toBe([]);
});

it('names each managed partition by its table', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-04-01T00:00:00Z'), new DateTimeImmutable('2026-04-02T00:00:00Z'));
    $partitions = 0;

    foreach (DB::connection('pgsql_owner')->select("select c.relname::text as name from pg_class c where c.relispartition and c.relname ~ '_p[0-9]+$'") as $row) {
        $name = is_object($row) && property_exists($row, 'name') && is_string($row->name) ? $row->name : throw new LogicException('Expected a partition name.');

        expect(KernelTables::of($name))->not->toBeNull($name);
        $partitions++;
    }

    expect($partitions)->toBeGreaterThan(0);
});
