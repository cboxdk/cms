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
 */
#[Experimental]
final readonly class TestDatabaseName
{
    /** How many hex digits of the SHA-256 the name keeps. */
    public const int HASH_DIGITS = 12;

    /** The longest name Postgres keeps in full (NAMEDATALEN - 1). */
    public const int MAX_BYTES = 63;

    /**
     * The test database of the checkout at $root, derived from the configured database $base.
     *
     * @throws InvalidArgumentException when $root is not a directory, $base is empty or the name exceeds 63 bytes
     */
    public static function for(string $base, string $root): string
    {
        if ($base === '') {
            throw new InvalidArgumentException('The configured test database has no name.');
        }

        $name = $base.self::suffix($root);

        if (strlen($name) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The test database name %s has %d bytes; Postgres keeps at most %d. Shorten the configured database %s to at most %d bytes.',
                $name,
                strlen($name),
                self::MAX_BYTES,
                $base,
                self::MAX_BYTES - strlen(self::suffix($root)),
            ));
        }

        return $name;
    }

    /**
     * The configured database that $database was derived from for the checkout at $root: $database
     * without the checkout's suffix, or $database itself when it does not end with the suffix.
     */
    public static function base(string $database, string $root): string
    {
        $suffix = self::suffix($root);

        return str_ends_with($database, $suffix) && strlen($database) > strlen($suffix)
            ? substr($database, 0, -strlen($suffix))
            : $database;
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
