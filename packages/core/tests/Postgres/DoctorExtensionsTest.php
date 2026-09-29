<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\ExtensionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Illuminate\Support\Facades\DB;

/*
 * postgres.extensions on the Postgres 18 service of compose.yaml (PRD 4.2, 5.8): the probe the core
 * binds reads pg_extension as the app role, and the check passes the checkout's migrated database,
 * where the core's migration created ltree as the owner role, and fails a scratch database that the
 * owner role creates without running the migrations.
 */

const EXTENSIONS_SCRATCH_CONNECTION = 'pgsql_doctor_extensions_scratch';

const EXTENSIONS_SCRATCH_OWNER = 'pgsql_doctor_extensions_owner';

afterEach(function (): void {
    DB::purge(DoctorConnection::NAME);
    DB::purge(EXTENSIONS_SCRATCH_CONNECTION);
    DB::purge(EXTENSIONS_SCRATCH_OWNER);
    ScratchDatabase::drop();
});

it('passes the checkout\'s migrated database, where the owner role created ltree', function (): void {
    $probe = app(PostgresProbe::class);
    $result = new ExtensionsCheck($probe)->run();

    expect($probe->extensions()->names)->toContain('ltree')
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
        ->and($result->explanation)->toBe(sprintf('The database %s has the extensions the core needs: ltree.', DB::connection()->getDatabaseName()));
});

it('fails with its code in a database without ltree', function (): void {
    $database = ScratchDatabase::create();
    ScratchDatabase::connection(EXTENSIONS_SCRATCH_CONNECTION, 'pgsql');
    config(['cbox-cms.doctor.connection' => EXTENSIONS_SCRATCH_CONNECTION]);

    $probe = app(PostgresProbe::class);
    $result = new ExtensionsCheck($probe)->run();

    expect($probe->extensions()->database)->toBe($database)
        ->and($probe->extensions()->names)->not->toContain('ltree')
        ->and($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->blocking)->toBeTrue()
        ->and($result->code)->toBe(ExtensionsCheck::CODE)
        ->and($result->cause)->toBe(sprintf('The database %s lacks the extensions ltree.', $database));

    // The owner role, which owns the database, can create it there without a superuser, as the
    // core's migration does: ltree is a trusted extension.
    ScratchDatabase::connection(EXTENSIONS_SCRATCH_OWNER, 'pgsql_owner');
    DB::connection(EXTENSIONS_SCRATCH_OWNER)->statement('create extension ltree schema public');

    expect(new ExtensionsCheck($probe)->run()->status)->toBe(CheckStatus::Pass);
});
