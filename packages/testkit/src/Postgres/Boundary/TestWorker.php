<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The parallel worker this process is, as the environment says: the number in TEST_TOKEN, or
 * null outside a parallel run.
 *
 * ParaTest, which Pest's --parallel runs, sets PARATEST and gives each worker process a
 * TEST_TOKEN from 1 up; the mutation plugin does the same for its runs of single mutations, one
 * token per slot. A process keeps its token while it runs, and two processes that run at the same
 * time never have the same one, so the token names a database only one process uses at a time
 * (TestDatabaseName). A parallel run without tokens (ParaTest's --no-test-tokens) is refused,
 * because its workers would share one database.
 */
#[Experimental]
final readonly class TestWorker
{
    /** Set by ParaTest in every worker process. */
    public const string PARALLEL = 'PARATEST';

    /** The worker's number, from 1 up. */
    public const string TOKEN = 'TEST_TOKEN';

    /**
     * The worker of this process.
     *
     * @throws InvalidArgumentException when the environment names no valid worker in a parallel run
     */
    public static function current(): ?int
    {
        return self::of(getenv());
    }

    /**
     * The worker the environment $environment names.
     *
     * @param  array<string, string>  $environment
     *
     * @throws InvalidArgumentException when a parallel run has no TEST_TOKEN, or TEST_TOKEN is not a positive integer
     */
    public static function of(array $environment): ?int
    {
        $token = $environment[self::TOKEN] ?? null;

        if ($token === null || $token === '') {
            if (isset($environment[self::PARALLEL])) {
                throw new InvalidArgumentException(sprintf(
                    'This process is a parallel worker (%s is set) without %s, so it cannot have a test database of its own. Run ParaTest without --no-test-tokens.',
                    self::PARALLEL,
                    self::TOKEN,
                ));
            }

            return null;
        }

        if (preg_match('/\A[1-9][0-9]{0,8}\z/', $token) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is "%s", not a positive integer.', self::TOKEN, $token));
        }

        return (int) $token;
    }
}
