<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;
use SplFileObject;

/**
 * Lets one PHPStan process at a time boot Laravel for Larastan.
 *
 * Larastan boots a Laravel application in PHPStan's main process and in every parallel worker,
 * and the workers start at the same moment. For a package that application is Testbench's
 * skeleton in vendor/orchestra/testbench-core/laravel, and the boot writes shared files there:
 * it rewrites bootstrap/cache/services.php (Larastan creates one application that ignores package
 * discovery and one that does not, so their service lists differ), and when the skeleton's vendor
 * symlink points at another path, such as the host's after a run outside the php container, it
 * replaces the link and deletes and rebuilds bootstrap/cache/packages.php. A worker that reads a
 * manifest while another replaces or deletes it fails with "No such file or directory", and
 * Larastan stops with "Laravel framework bootstrap failed". Through a Docker Desktop bind mount
 * that happened in about one run in three.
 *
 * The testkit's phpstan-boot-lock.php takes an exclusive lock before Larastan's bootstrap and
 * phpstan-boot-unlock.php releases it after, so the boots run one after the other and each finds
 * the files the one before left. The lock is a file in the system temp directory, one per working
 * directory, because Larastan boots the application of the directory PHPStan runs in. The
 * operating system releases it when a process exits, also when Larastan's bootstrap fails.
 */
#[Internal]
final class LaravelBootLock
{
    private static ?SplFileObject $held = null;

    /**
     * Waits until no other process holds the lock for $workingDirectory, then takes it.
     */
    public static function acquire(string $lockDirectory, string $workingDirectory): void
    {
        if (self::$held instanceof SplFileObject) {
            return;
        }

        $lock = new SplFileObject(self::path($lockDirectory, $workingDirectory), 'c');

        if (! $lock->flock(LOCK_EX)) {
            throw new RuntimeException("Could not lock {$lock->getPathname()} for Larastan's boot of Laravel.");
        }

        self::$held = $lock;
    }

    public static function release(): void
    {
        if (! self::$held instanceof SplFileObject) {
            return;
        }

        self::$held->flock(LOCK_UN);
        self::$held = null;
    }

    public static function path(string $lockDirectory, string $workingDirectory): string
    {
        return rtrim($lockDirectory, '/').'/cbox-cms-laravel-boot-'.hash('xxh128', $workingDirectory).'.lock';
    }
}
