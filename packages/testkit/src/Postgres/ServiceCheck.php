<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use PDO;
use PDOException;

/**
 * Fails the Postgres suite fast when the test services are not running.
 *
 * It opens a raw PDO connection with a short connect timeout for each role, so a stopped
 * container fails the first test at once instead of after Laravel's default timeouts. The
 * message names the checkout's own test database, which the harness creates on that server
 * (TestDatabase), and the fix. TestDatabase::ensure() keeps the result for the rest of the
 * process: after one failure every later test fails immediately with the same message.
 */
#[Experimental]
final class ServiceCheck
{
    public const int CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Connects once as each role and returns why it failed, or null when every role connects.
     *
     * @param  string  $testDatabase  the checkout's test database, which the message names
     */
    public static function probe(string $testDatabase, ConnectionSettings ...$connections): ?string
    {
        foreach ($connections as $connection) {
            try {
                new PDO($connection->dsn(self::CONNECT_TIMEOUT_SECONDS), $connection->username, $connection->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                ]);
            } catch (PDOException $exception) {
                return self::message($connection, $testDatabase, $exception->getMessage());
            }
        }

        return null;
    }

    public static function message(ConnectionSettings $connection, string $testDatabase, string $reason): string
    {
        return sprintf(
            "The Postgres test service is not reachable at %s:%d (database %s, role %s, connection [%s]).\n"
            ."The tests of this checkout run in the database %s, which the harness creates on that server.\n"
            ."Start the services with `composer services:up` and run the suite again.\n"
            .'Reason: %s',
            $connection->host,
            $connection->port,
            $connection->database,
            $connection->username,
            $connection->name,
            $testDatabase,
            preg_replace('/\s+/', ' ', trim($reason)),
        );
    }
}
