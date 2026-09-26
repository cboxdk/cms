<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature;

use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Illuminate\Contracts\Config\Repository;

/*
 * The TestCase that every suite extends (tests/Pest.php) points every pgsql connection at this
 * checkout's own test database before the first connection opens, so no suite reaches the
 * configured cms_test, which every checkout shares. The Postgres suite's harness provisions and
 * migrates the database; tests/Postgres/CheckoutDatabaseTest.php checks the same there.
 */

it('points the app and owner connections at this checkout\'s test database', function (): void {
    $name = CheckoutDatabase::name();

    expect(CheckoutDatabase::pgsqlDatabases(app(Repository::class)))->toMatchArray(['pgsql' => $name, 'pgsql_owner' => $name])
        ->and(array_unique(CheckoutDatabase::pgsqlDatabases(app(Repository::class))))->toBe(['pgsql' => $name])
        ->and($name)->toMatch('/\Acms_test_[0-9a-f]{12}\z/');
});

it('gives the doctor\'s copy of the app connection the same database, without connecting', function (): void {
    app(DoctorConnection::class)->get();

    expect(config('database.connections.'.DoctorConnection::NAME.'.database'))->toBe(CheckoutDatabase::name())
        ->and(app(DoctorConnection::class)->target())->toEndWith('/'.CheckoutDatabase::name().' (connection pgsql)');
});
