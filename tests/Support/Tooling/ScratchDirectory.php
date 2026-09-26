<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Temporary directories under the real path of the system temporary directory, removed after
 * each test without following symlinks.
 */
final class ScratchDirectory
{
    /** @var list<string> */
    private static array $created = [];

    public static function make(string $prefix = 'cbox-cms-tooling-test-'): string
    {
        $temporary = realpath(sys_get_temp_dir()) ?: throw new RuntimeException('No temporary directory.');
        $path = $temporary.'/'.$prefix.bin2hex(random_bytes(6));

        if (! mkdir($path, 0o700)) {
            throw new RuntimeException("Cannot create {$path}.");
        }

        self::$created[] = $path;

        return $path;
    }

    public static function write(string $file, string $contents = ''): string
    {
        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0o777, true)) {
            throw new RuntimeException("Cannot create the directory for {$file}.");
        }

        file_put_contents($file, $contents);

        return $file;
    }

    public static function cleanUp(): void
    {
        foreach (self::$created as $path) {
            self::delete($path);
        }

        self::$created = [];
    }

    public static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo) {
                self::delete($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
