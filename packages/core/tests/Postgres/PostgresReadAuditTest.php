<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Reads\Adapter\PostgresReadAudit;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * The read audit on Postgres (PRD 12.12): one row per audited entry of a read, with the read's id
 * shared by its rows, the actor, the query, the classification, the field addresses and the read's
 * position, and no value. The app role writes only rows of the context's actor and reads none, and
 * a read on a day no partition covers is refused with PartitionMissing.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

const AUDITED_OTHER_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e2';

it('is the container\'s read audit and writes one row per audited entry with the read\'s id, the fields and the position', function (): void {
    $tables = ReadAuditTables::at(new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z')));
    $app = DB::connection();

    $app->beginTransaction();
    $tables->context();
    $tables->audit()->record($tables->record(ReadAuditTables::ENTRY, AUDITED_OTHER_ENTRY));
    $app->commit();

    $rows = $tables->rows();
    $readIds = array_unique(array_map(static fn (string $row): string => explode(' | ', $row)[0], $rows));

    expect(app(ReadAudit::class))->toBeInstanceOf(PostgresReadAudit::class)
        ->and($readIds)->toHaveCount(1)
        ->and(str_starts_with((string) array_first($readIds), '019cd7'))->toBeTrue()
        ->and(array_map(static fn (string $row): string => substr($row, strpos($row, ' | ') + 3), $rows))->toBe([
            sprintf('%s | %s | probe.read | 2 | sensitive | diagnosis,ext.probe.code | 4827', ReadAuditTables::ENTRY, $tables->actor->toString()),
            sprintf('%s | %s | probe.read | 2 | sensitive | diagnosis,ext.probe.code | 4827', AUDITED_OTHER_ENTRY, $tables->actor->toString()),
        ])
        ->and($app->table('read_audit')->count())->toBe(0);
});

it('refuses a row of another actor than the context\'s, and writes nothing', function (): void {
    $tables = ReadAuditTables::at();
    $app = DB::connection();

    $app->beginTransaction();
    $tables->context(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c9'));

    try {
        expect(fn () => $tables->audit()->record($tables->record()))->toThrow(QueryException::class, 'row-level security policy for table "read_audit"');
    } finally {
        $app->rollBack();
    }

    expect($tables->rows())->toBe([]);
});

it('refuses a read on a day no partition covers with PartitionMissing, and writes nothing', function (): void {
    $tables = ReadAuditTables::at();
    $later = new FakeClock(new DateTimeImmutable('2040-01-01T00:00:00Z'));
    $audit = new PostgresReadAudit(app(DatabaseManager::class), $later, new FakeIdGenerator(clock: $later));
    $app = DB::connection();

    $app->beginTransaction();
    $tables->context();

    try {
        expect(fn () => $audit->record($tables->record()))->toThrow(PartitionMissing::class);
    } finally {
        $app->rollBack();
    }

    expect($tables->rows())->toBe([]);
});

it('makes its tables only before a transaction opens, because a snapshot already taken never sees the actor the owner commits', function (): void {
    $app = DB::connection();

    $app->beginTransaction();

    try {
        expect(fn (): ReadAuditTables => ReadAuditTables::at())->toThrow(LogicException::class, 'made before a transaction opens on the default connection, which has 1 open');
    } finally {
        $app->rollBack();
    }

    expect(StorageTables::superuser()->table('actors')->count())->toBe(0);
});
