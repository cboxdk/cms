<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/*
 * postgres.row_security on the Postgres 18 service of compose.yaml (PRD 4.2): the probe the core
 * binds reads pg_class as the app role, and the check fails a scratch table that the owner role
 * gives row level security without FORCE ROW LEVEL SECURITY, and a partition that misses FORCE
 * while its parent has it.
 */

const ROW_SECURITY_SCRATCH = 'doctor_row_security_scratch';

function rowSecurityOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function dropRowSecurityScratch(): void
{
    $owner = rowSecurityOwner();
    $owner->statement("set lock_timeout = '5s'");
    $owner->statement(sprintf('drop table if exists %s cascade', ROW_SECURITY_SCRATCH));
    $owner->statement('reset lock_timeout');
}

/**
 * The schema-qualified name the probe gives the scratch table or one of its partitions.
 */
function rowSecurityName(string $table): string
{
    $name = rowSecurityOwner()->scalar("select format('%I.%I', current_schema(), ?::text)", [$table]);

    return is_string($name) ? $name : throw new UnexpectedValueException('Expected a name.');
}

beforeEach(function (): void {
    dropRowSecurityScratch();
});

afterEach(function (): void {
    dropRowSecurityScratch();
    DB::purge(DoctorConnection::NAME);
});

it('binds the connection probe and passes the provisioned schema, whose row level security is all forced', function (): void {
    $probe = app(PostgresProbe::class);
    $security = $probe->rowSecurity();
    $result = new RowSecurityCheck($probe)->run();

    expect($probe)->toBeInstanceOf(ConnectionPostgresProbe::class)
        ->and($security->unforcedTables)->toBe([])
        ->and($security->unforcedCount)->toBe(0)
        ->and($security->database)->toBe(rowSecurityOwner()->getDatabaseName())
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);
});

it('fails a table with row level security that its owner does not force, and passes once it does', function (): void {
    $before = app(PostgresProbe::class)->rowSecurity()->enabledCount;
    rowSecurityOwner()->statement(sprintf('create table %s (id bigint primary key)', ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf('alter table %s enable row level security', ROW_SECURITY_SCRATCH));

    $probe = app(PostgresProbe::class);
    $security = $probe->rowSecurity();
    $result = new RowSecurityCheck($probe)->run();

    expect($security->enabledCount)->toBe($before + 1)
        ->and($security->unforcedTables)->toBe([rowSecurityName(ROW_SECURITY_SCRATCH)])
        ->and($security->unforcedCount)->toBe(1)
        ->and($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(RowSecurityCheck::CODE)
        ->and($result->cause)->toContain('such as '.rowSecurityName(ROW_SECURITY_SCRATCH).'.')
        ->and($result->fix)->toContain(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', rowSecurityName(ROW_SECURITY_SCRATCH)));

    rowSecurityOwner()->statement(sprintf('alter table %s force row level security', ROW_SECURITY_SCRATCH));

    expect(new RowSecurityCheck(app(PostgresProbe::class))->run()->status)->toBe(CheckStatus::Pass);
});

it('ignores a table that forces row level security it has not enabled', function (): void {
    rowSecurityOwner()->statement(sprintf('create table %s (id bigint primary key)', ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf('alter table %s force row level security', ROW_SECURITY_SCRATCH));

    expect(new RowSecurityCheck(app(PostgresProbe::class))->run()->status)->toBe(CheckStatus::Pass);
});

it('names an unforced partitioned table before its unforced partitions, and a partition that misses FORCE under a forced parent', function (): void {
    $partition = ROW_SECURITY_SCRATCH.'_p20260310';
    rowSecurityOwner()->statement(sprintf('create table %s (at timestamptz not null) partition by range (at)', ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf("create table %s partition of %s for values from ('2026-03-10') to ('2026-03-11')", $partition, ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf('alter table %s enable row level security', ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf('alter table %s enable row level security', $partition));

    $both = app(PostgresProbe::class)->rowSecurity();

    rowSecurityOwner()->statement(sprintf('alter table %s force row level security', ROW_SECURITY_SCRATCH));
    $result = new RowSecurityCheck(app(PostgresProbe::class))->run();

    expect($both->unforcedTables)->toBe([rowSecurityName(ROW_SECURITY_SCRATCH), rowSecurityName($partition)])
        ->and($both->unforcedCount)->toBe(2)
        ->and($result->status)->toBe(CheckStatus::Fail)
        ->and($result->cause)->toContain('such as '.rowSecurityName($partition).'.')
        ->and($result->cause)->not->toContain(rowSecurityName(ROW_SECURITY_SCRATCH).',');
});

it('names the first five unforced tables and counts all of them', function (): void {
    rowSecurityOwner()->statement(sprintf('create table %s (at timestamptz not null) partition by range (at)', ROW_SECURITY_SCRATCH));
    rowSecurityOwner()->statement(sprintf('alter table %s enable row level security', ROW_SECURITY_SCRATCH));

    foreach (range(10, 15) as $day) {
        $partition = sprintf('%s_p202603%d', ROW_SECURITY_SCRATCH, $day);
        rowSecurityOwner()->statement(sprintf("create table %s partition of %s for values from ('2026-03-%d') to ('2026-03-%d')", $partition, ROW_SECURITY_SCRATCH, $day, $day + 1));
        rowSecurityOwner()->statement(sprintf('alter table %s enable row level security', $partition));
    }

    $security = app(PostgresProbe::class)->rowSecurity();

    expect($security->unforcedCount)->toBe(7)
        ->and($security->unforcedTables)->toBe([
            rowSecurityName(ROW_SECURITY_SCRATCH),
            rowSecurityName(ROW_SECURITY_SCRATCH.'_p20260310'),
            rowSecurityName(ROW_SECURITY_SCRATCH.'_p20260311'),
            rowSecurityName(ROW_SECURITY_SCRATCH.'_p20260312'),
            rowSecurityName(ROW_SECURITY_SCRATCH.'_p20260313'),
        ]);
});
