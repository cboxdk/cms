<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/*
 * The Postgres suite runs in this checkout's own test database, which the harness provisioned and
 * migrated: every pgsql connection names it, and so do the connections the helpers and the doctor
 * open from them.
 */

it('names this checkout\'s database on every pgsql connection, and runs there as the app and owner roles', function (): void {
    $name = CheckoutDatabase::name();

    expect(array_unique(CheckoutDatabase::pgsqlDatabases(app(Repository::class))))->toBe(['pgsql' => $name])
        ->and(DB::scalar('select current_database()'))->toBe($name)
        ->and(DB::connection('pgsql_owner')->scalar('select current_database()'))->toBe($name)
        ->and(DB::table('migrations')->count())->toBeGreaterThan(0);
});

it('opens the independent connections and the child processes on this checkout\'s database', function (): void {
    $name = CheckoutDatabase::name();
    [$app, $owner] = [...app(IndependentConnections::class)->open(1), ...app(IndependentConnections::class)->open(1, 'pgsql_owner')];
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {
        $database = $context->connection()->scalar('select current_database()');
        $context->signal(is_string($database) ? $database : 'no database');
    });

    $child->waitForSignal($name);
    $child->wait();

    expect($app->scalar('select current_database()'))->toBe($name)
        ->and($owner->scalar('select current_database()'))->toBe($name);
});

it('gives the doctor\'s copies of the app and owner connections, cms_doctor and cms_doctor_owner, this checkout\'s database', function (): void {
    $probe = app(LcMessagesProbe::class);
    $probe->appRole();
    $probe->ownerRole();

    expect(config('database.connections.'.DoctorConnection::NAME.'.database'))->toBe(CheckoutDatabase::name())
        ->and(config('database.connections.'.DoctorConnection::OWNER_NAME.'.database'))->toBe(CheckoutDatabase::name())
        ->and(DB::connection(DoctorConnection::NAME)->scalar('select current_database()'))->toBe(CheckoutDatabase::name())
        ->and(DB::connection(DoctorConnection::OWNER_NAME)->scalar('select current_database()'))->toBe(CheckoutDatabase::name());
});
