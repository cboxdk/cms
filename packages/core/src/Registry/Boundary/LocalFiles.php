<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use SplFileObject;
use UnexpectedValueException;

/**
 * Reads the local files cms:build checks the panel against (PRD 13.4): the props schemas of the
 * panel points in the directories the modules register, and the manifest and files of an addon's
 * panel bundle in the directory its manifest names. It reads local files and never a URL: a path
 * that names a stream wrapper is refused before any file function sees it, which is why the Arch
 * suite allows it SplFileObject and FilesystemIterator (Egress).
 */
#[Internal]
final readonly class LocalFiles
{
    /**
     * The bytes of the file, or null when it is no readable local file.
     */
    public static function read(string $path): ?string
    {
        if (LocalPath::namesStreamWrapper($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 || $size === false ? '' : $file->fread($size);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }

    /**
     * The files directly in the directory whose names end in the suffix, by name and sorted; none
     * when it is no readable local directory.
     *
     * @return array<string, string> path by file name
     */
    public static function files(string $directory, string $suffix): array
    {
        if (LocalPath::namesStreamWrapper($directory) || ! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $files = [];

        try {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $files[$file->getFilename()] = $file->getPathname();
                }
            }
        } catch (UnexpectedValueException) {
            return [];
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
