<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Valkey\Boundary\ValkeySettings;
use PHPUnit\Framework\AssertionFailedError;
use RedisException;

/**
 * Fails the suite fast when the Valkey test service is not running.
 *
 * It opens a raw client with a short timeout, selects the test database and sends PING, so a
 * stopped container fails the first test at once. The result is kept for the rest of the process:
 * after one failure every later test fails immediately with the same message, and after one
 * success the check is skipped. The message is the one the Postgres check gives: start the
 * services with `composer services:up`.
 */
#[Experimental]
final class ValkeyServiceCheck
{
    private static ?string $failure = null;

    private static bool $passed = false;

    public static function ensureReachable(ValkeySettings $settings): void
    {
        if (self::$failure === null && ! self::$passed) {
            self::$failure = self::probe($settings);
            self::$passed = self::$failure === null;
        }

        if (self::$failure !== null) {
            throw new AssertionFailedError(self::$failure);
        }
    }

    /**
     * Connects once and returns why it failed, or null when Valkey answers.
     */
    public static function probe(ValkeySettings $settings, float $timeoutSeconds = ValkeyConnector::TIMEOUT_SECONDS): ?string
    {
        try {
            $client = ValkeyConnector::connect($settings, timeoutSeconds: $timeoutSeconds);

            try {
                if ($client->ping() === false) {
                    return self::message($settings, 'PING got no reply.');
                }
            } finally {
                $client->close();
            }
        } catch (RedisException $exception) {
            return self::message($settings, $exception->getMessage());
        }

        return null;
    }

    public static function message(ValkeySettings $settings, string $reason): string
    {
        return sprintf(
            "The Valkey test service is not reachable at %s:%d (database %d, connection [%s]).\n"
            ."Start the services with `composer services:up` and run the suite again.\n"
            .'Reason: %s',
            $settings->host,
            $settings->port,
            $settings->database,
            $settings->name,
            preg_replace('/\s+/', ' ', trim($reason)),
        );
    }
}
