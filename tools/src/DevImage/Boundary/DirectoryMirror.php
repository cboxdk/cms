<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Boundary;

use FilesystemIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Makes a directory hold exactly what another holds: it removes everything below the target, then
 * copies every file and directory of the source, with the files' permissions. Symbolic links are
 * neither followed nor copied. tools/bin/dev-image-entry.php mirrors the host's bootstrap cache of
 * the Testbench application into the checkout's volume with it (VolumeKind::BootstrapCache).
 */
final readonly class DirectoryMirror
{
    public static function mirror(string $source, string $target): void
    {
        if (! is_dir($source) || ! is_dir($target)) {
            throw new UnexpectedValueException("Cannot mirror {$source} into {$target}: both must be directories.");
        }

        foreach (self::entries($target) as $entry) {
            self::remove($entry->getPathname());
        }

        self::copy($source, $target);
    }

    private static function copy(string $source, string $target): void
    {
        foreach (self::entries($source) as $entry) {
            $to = $target.'/'.$entry->getFilename();

            if ($entry->isLink()) {
                continue;
            }

            if ($entry->isDir()) {
                if (! is_dir($to) && ! mkdir($to, 0o755) && ! is_dir($to)) {
                    throw new UnexpectedValueException("Cannot create {$to}.");
                }

                self::copy($entry->getPathname(), $to);

                continue;
            }

            if (! copy($entry->getPathname(), $to) || ! chmod($to, $entry->getPerms() & 0o777)) {
                throw new UnexpectedValueException("Cannot copy {$entry->getPathname()} to {$to}.");
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (self::entries($path) as $entry) {
            self::remove($entry->getPathname());
        }

        rmdir($path);
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function entries(string $directory): array
    {
        $entries = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
