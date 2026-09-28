<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The name of a checkout's own Postgres test database.
 *
 * Every checkout of a repository, a git worktree included, runs its tests in a database of its
 * own, so two checkouts can run the Postgres suite at the same time without seeing each other's
 * rows. The name is the configured database, `_`, and the first 12 hex digits of the SHA-256 of
 * the real path of the checkout root: `cms_test` becomes `cms_test_3f9a0c21d4e7`. The same path
 * gives the same name, a symlink to it gives the name of its real path, and another path gives
 * another name. Postgres keeps at most 63 bytes of a name, so a longer result is refused rather
 * than cut short.
 *
 * A parallel run (Pest's --parallel, and the mutation plugin's runs of single mutations) gives
 * each worker a database of its own as well: the checkout's name, `_w` and the worker's number
 * (TestWorker), such as `cms_test_3f9a0c21d4e7_w2`. The same checkout and worker give the same
 * name, so a worker that starts again finds its database, and two workers never share rows.
 */
#[Experimental]
final readonly class TestDatabaseName
{
    /** How many hex digits of the SHA-256 the name keeps. */
    public const int HASH_DIGITS = 12;

    /** The longest name Postgres keeps in full (NAMEDATALEN - 1). */
    public const int MAX_BYTES = 63;

    /** What comes between the checkout's name and a worker's number. */
    public const string WORKER_SEPARATOR = '_w';

    /** A worker's number as it ends a name: a positive integer without leading zeros. */
    public const string WORKER_PATTERN = '[1-9][0-9]*';

    /**
     * The test database of the checkout at $root, derived from the configured database $base, or
     * of the parallel worker $worker of that checkout when $worker is not null.
     *
     * @throws InvalidArgumentException when $root is not a directory, $base is empty, $worker is below 1 or the name exceeds 63 bytes
     */
    public static function for(string $base, string $root, ?int $worker = null): string
    {
        if ($base === '') {
            throw new InvalidArgumentException('The configured test database has no name.');
        }

        $suffix = self::suffix($root).self::workerSuffix($worker);
        $name = $base.$suffix;

        if (strlen($name) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The test database name %s has %d bytes; Postgres keeps at most %d. Shorten the configured database %s to at most %d bytes.',
                $name,
                strlen($name),
                self::MAX_BYTES,
                $base,
                self::MAX_BYTES - strlen($suffix),
            ));
        }

        return $name;
    }

    /**
     * `_w` and the number of the parallel worker $worker, or nothing for a run without workers.
     *
     * @throws InvalidArgumentException when $worker is below 1
     */
    public static function workerSuffix(?int $worker): string
    {
        if ($worker === null) {
            return '';
        }

        if ($worker < 1) {
            throw new InvalidArgumentException(sprintf('A parallel worker has a number of 1 or more, not %d.', $worker));
        }

        return self::WORKER_SEPARATOR.$worker;
    }

    /**
     * The configured database that $database was derived from for the checkout at $root: $database
     * without the checkout's suffix and a worker's, or $database itself when it does not end with
     * the checkout's suffix, alone or followed by a worker's.
     */
    public static function base(string $database, string $root): string
    {
        $pattern = '/\A(.+)'.preg_quote(self::suffix($root), '/').'(?:'.self::WORKER_SEPARATOR.self::WORKER_PATTERN.')?\z/s';

        return preg_match($pattern, $database, $matches) === 1 ? $matches[1] : $database;
    }

    /**
     * `_` and the first 12 hex digits of the SHA-256 of the real path of $root.
     *
     * @throws InvalidArgumentException when $root is not a directory
     */
    public static function suffix(string $root): string
    {
        return '_'.substr(hash('sha256', self::realpath($root)), 0, self::HASH_DIGITS);
    }

    /**
     * @throws InvalidArgumentException when $root is not a directory
     */
    public static function realpath(string $root): string
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException(sprintf('The checkout root %s is not a directory.', $root));
        }

        return $real;
    }
}
