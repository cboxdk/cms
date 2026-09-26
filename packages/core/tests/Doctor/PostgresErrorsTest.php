<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\PostgresErrors;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;

/*
 * pdo_pgsql reports every failed connection as SQLSTATE 08006, so the doctor sorts them by the
 * text: a refusal from the server is a violation unless the server is starting, stopping,
 * recovering or full; anything before the server answered is unavailable.
 */

it('sorts connection failures into unavailable and violation', function (string $message, FailureKind $kind): void {
    $failed = PostgresErrors::classify(new PDOException($message));

    expect($failed->kind)->toBe($kind)
        ->and($failed->cause)->toBe(trim((string) preg_replace('/\s+/', ' ', $message)));
})->with([
    'connection refused' => ["SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 1 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?", FailureKind::Unavailable],
    'timeout' => ['SQLSTATE[08006] [7] connection to server at "10.255.255.1", port 5432 failed: timeout expired', FailureKind::Unavailable],
    'unknown host' => ['SQLSTATE[08006] [7] could not translate host name "postgres" to address: nodename nor servname provided', FailureKind::Unavailable],
    'wrong password' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 54317 failed: FATAL:  password authentication failed for user "cms_app"', FailureKind::Violation],
    'unknown database' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 54317 failed: FATAL:  database "nope" does not exist', FailureKind::Violation],
    'starting up' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 54317 failed: FATAL:  the database system is starting up', FailureKind::Unavailable],
    'shutting down' => ['SQLSTATE[08006] [7] connection to server failed: FATAL:  the database system is shutting down', FailureKind::Unavailable],
    'recovery' => ['SQLSTATE[08006] [7] connection to server failed: FATAL:  the database system is in recovery mode', FailureKind::Unavailable],
    'full' => ['SQLSTATE[08006] [7] connection to server failed: FATAL:  sorry, too many clients already', FailureKind::Unavailable],
]);

it('reads the driver\'s message from inside Laravel\'s query exception', function (): void {
    $pdo = new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused');
    $query = new QueryException('cms_doctor', 'select 1 as one', [], $pdo);

    expect(PostgresErrors::classify($query)->cause)->toBe($pdo->getMessage())
        ->and(PostgresErrors::classify(new RuntimeException(''))->cause)->toBe('The driver gave no message.');
});

it('passes a probe failure through unchanged', function (): void {
    $failed = ProbeFailed::violation('Not a Postgres connection.');

    expect(PostgresErrors::classify($failed))->toBe($failed);
});

it('reads the driver\'s message below any wrapper, and keeps the failure it classified', function (): void {
    $pdo = new PDOException('SQLSTATE[08006] [7] connection to server failed: FATAL:  role "nobody" does not exist');
    $wrapped = new RuntimeException('Could not open the connection.', 0, $pdo);
    $failed = PostgresErrors::classify($wrapped);

    expect($failed->cause)->toBe('SQLSTATE[08006] [7] connection to server failed: FATAL: role "nobody" does not exist')
        ->and($failed->kind)->toBe(FailureKind::Violation)
        ->and($failed->getPrevious())->toBe($wrapped)
        ->and(PostgresErrors::classify(new RuntimeException('no driver below'))->cause)->toBe('no driver below');
});

it('trims the message to one line, and names an empty or blank one', function (): void {
    expect(PostgresErrors::classify(new PDOException("  refused\n\tagain  "))->cause)->toBe('refused again')
        ->and(PostgresErrors::classify(new PDOException(" \n\t "))->cause)->toBe('The driver gave no message.');
});

it('treats the server\'s temporary refusals as unavailable in any case', function (string $message): void {
    expect(PostgresErrors::classify(new PDOException('SQLSTATE[08006] [7] connection to server failed: FATAL:  '.$message))->kind)->toBe(FailureKind::Unavailable);
})->with([
    'The Database System Is Starting Up',
    'the database system is not yet accepting connections',
    'the database system is not accepting connections',
    'remaining connection slots are reserved for roles with the SUPERUSER attribute',
]);
