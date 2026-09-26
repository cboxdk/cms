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
