<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabasePayload;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * The body of bin/test-database.php, a child process that provisions the test database of the
 * checkout its payload names (TestDatabase::command()).
 *
 * Exit codes: 0 with the database's name on standard output, 1 when provisioning failed, with
 * the reason on standard error, 2 when the payload was invalid.
 */
#[Internal]
final class TestDatabaseMain
{
    public const int OK = 0;

    public const int FAILED = 1;

    public const int INVALID = 2;

    /**
     * @param  Closure(string): void  $stdout
     * @param  Closure(string): void  $stderr
     */
    public static function run(string $input, Closure $stdout, Closure $stderr): int
    {
        try {
            $payload = TestDatabasePayload::decode($input);
        } catch (InvalidArgumentException $exception) {
            $stderr($exception->getMessage()."\n");

            return self::INVALID;
        }

        try {
            $stdout(TestDatabase::provision($payload->owner, $payload->app, $payload->identity, $payload->root, $payload->worker)."\n");
        } catch (Throwable $exception) {
            $stderr(sprintf("%s: %s\n", $exception::class, $exception->getMessage()));

            return self::FAILED;
        }

        return self::OK;
    }
}
