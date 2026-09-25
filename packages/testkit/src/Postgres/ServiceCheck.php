<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use PDO;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Fails the Postgres suite fast when the test services are not running.
 *
 * It opens a raw PDO connection with a short connect timeout for each role, so a stopped
 * container fails the first test at once instead of after Laravel's default timeouts. The
 * result is kept for the rest of the process: after one failure every later test fails
 * immediately with the same message, and after one success the check is skipped.
 */
#[Experimental]
final class ServiceCheck
{
    public const int CONNECT_TIMEOUT_SECONDS = 2;

    private static ?string $failure = null;

    private static bool $passed = false;

    public static function ensureReachable(ConnectionSettings ...$connections): void
    {
        if (self::$failure === null && ! self::$passed) {
            self::$failure = self::probe(...$connections);
            self::$passed = self::$failure === null;
        }

        if (self::$failure !== null) {
            throw new AssertionFailedError(self::$failure);
        }
    }

    /**
     * Connects once as each role and returns why it failed, or null when every role connects.
     */
    public static function probe(ConnectionSettings ...$connections): ?string
    {
        foreach ($connections as $connection) {
            try {
                new PDO($connection->dsn(self::CONNECT_TIMEOUT_SECONDS), $connection->username, $connection->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (PDOException $exception) {
                return self::message($connection, $exception->getMessage());
            }
        }

        return null;
    }

    public static function message(ConnectionSettings $connection, string $reason): string
    {
        return sprintf(
            "The Postgres test service is not reachable at %s:%d (database %s, role %s, connection [%s]).\n"
            ."Start the services with `composer services:up` and run the suite again.\n"
            .'Reason: %s',
            $connection->host,
            $connection->port,
            $connection->database,
            $connection->username,
            $connection->name,
            preg_replace('/\s+/', ' ', trim($reason)),
        );
    }
}
