<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use PDO;

/*
 * The doctor's own copy of an application connection, without a server: where it points, the
 * settings it registers under its own name with the short connect timeout, and how it fails.
 * DoctorLcMessagesTest in the Postgres suite reads through it for real.
 */

/**
 * @param  array<array-key, mixed>  $connection
 */
function doctorConnectionOf(array $connection, string $source = 'pgsql', string $name = 'cms_doctor_test'): DoctorConnection
{
    config(['database.connections.'.$source => $connection]);

    return new DoctorConnection(app(DatabaseManager::class), app(Repository::class), $source, $name, 2);
}

it('names the target as user@host:port/database with the source connection, and ? for what is missing', function (): void {
    expect(doctorConnectionOf(['driver' => 'pgsql', 'username' => 'cms_app', 'host' => 'postgres', 'port' => 5432, 'database' => 'cms'], 'doctor_target')->target())
        ->toBe('cms_app@postgres:5432/cms (connection doctor_target)')
        ->and(doctorConnectionOf(['driver' => 'pgsql', 'username' => '', 'host' => ['a'], 'database' => 'cms'], 'doctor_target')->target())
        ->toBe('?@?:?/cms (connection doctor_target)')
        ->and(new DoctorConnection(app(DatabaseManager::class), app(Repository::class), 'nowhere', 'cms_doctor_test', 2)->target())
        ->toBe('the connection nowhere, which is not configured');
});

it('registers a copy of the source under its own name, with the connect timeout on top of the source\'s options, and opens it once', function (): void {
    $doctor = doctorConnectionOf(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'cms', 'username' => 'cms_app', 'password' => 'secret', 'options' => [PDO::ATTR_CASE => PDO::CASE_NATURAL]], 'doctor_source');

    $connection = $doctor->get();
    $copy = config('database.connections.cms_doctor_test');

    expect($connection->getName())->toBe('cms_doctor_test')
        ->and($doctor->get())->toBe($connection)
        ->and($doctor->source)->toBe('doctor_source')
        ->and(is_array($copy) ? $copy['options'] : null)->toBe([PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_TIMEOUT => 2])
        ->and(is_array($copy) ? $copy['host'] : null)->toBe('127.0.0.1')
        ->and(config('database.connections.doctor_source.options'))->toBe([PDO::ATTR_CASE => PDO::CASE_NATURAL]);
});

it('refuses a source that is not a Postgres connection as a violation', function (array $connection): void {
    $doctor = doctorConnectionOf($connection, 'doctor_other');

    expect(static fn (): mixed => $doctor->get())->toThrow(ProbeFailed::class, 'The database connection doctor_other is not configured as a Postgres connection (driver pgsql) in config/database.php.');

    try {
        $doctor->get();
    } catch (ProbeFailed $failed) {
        expect($failed->kind)->toBe(FailureKind::Violation);
    }
})->with([
    'sqlite' => [['driver' => 'sqlite', 'database' => ':memory:']],
    'no driver' => [['host' => 'postgres']],
]);

it('classifies a query on a server that does not answer as unavailable', function (): void {
    $doctor = doctorConnectionOf(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'cms', 'username' => 'cms_app', 'password' => 'x'], 'doctor_closed');

    try {
        $doctor->rows('select 1');
        $failed = null;
    } catch (ProbeFailed $caught) {
        $failed = $caught;
    }

    expect($failed?->kind)->toBe(FailureKind::Unavailable)
        ->and($failed?->cause)->toContain('Connection refused');
});
