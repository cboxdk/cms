<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use InvalidArgumentException;

/**
 * The panel's build as its Vite manifest describes it (PRD 13.4): the directory it lies in, the
 * entry's script, the stylesheets and the chunks the entry loads at once, every file the build
 * may serve, and a version that changes with every build, so Inertia reloads a page whose assets
 * are out of date. A file is a path relative to the directory, such as `assets/app-1a2b3c.js`;
 * only the files the manifest names are ever served, so a request can never reach another file.
 */
#[Internal]
final readonly class PanelBuild
{
    /** A file of the build: relative path segments of letters, digits, dots, `_` and `-`. */
    public const string FILE_PATTERN = '[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*';

    /**
     * @param  string  $directory  the absolute directory of the build
     * @param  string  $entry  the entry's script
     * @param  list<string>  $styles  the stylesheets of the entry and of the chunks it imports, in order
     * @param  list<string>  $preloads  the chunks the entry imports, in order
     * @param  list<string>  $files  every file of the build, sorted
     * @param  string  $version  the SHA-256 of the manifest
     *
     * @throws InvalidArgumentException when a file is not a file of the build or the directory is not absolute
     */
    public function __construct(
        public string $directory,
        public string $entry,
        public array $styles,
        public array $preloads,
        public array $files,
        public string $version,
    ) {
        if (! str_starts_with($directory, '/') || LocalPath::namesStreamWrapper($directory)) {
            throw new InvalidArgumentException("The panel's build directory {$directory} is not an absolute local path.");
        }

        foreach ($files as $file) {
            if (! self::isFile($file)) {
                throw new InvalidArgumentException("The panel's build names {$file}, which is not a relative path inside the build.");
            }
        }

        foreach ([$entry, ...$styles, ...$preloads] as $file) {
            if (! in_array($file, $files, true)) {
                throw new InvalidArgumentException("The panel's build loads {$file}, which is not one of its files.");
            }
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', $version) !== 1) {
            throw new InvalidArgumentException("The panel's build version {$version} is not a SHA-256.");
        }
    }

    /**
     * Whether a path has the form of a file of a build: relative, without `.` or `..` segments.
     */
    public static function isFile(string $file): bool
    {
        return preg_match('~\A'.self::FILE_PATTERN.'\z~', $file) === 1
            && ! in_array('..', explode('/', $file), true);
    }

    /**
     * Whether the build has the file, so it may be served.
     */
    public function serves(string $file): bool
    {
        return in_array($file, $this->files, true);
    }

    /**
     * The absolute path of a file of the build.
     *
     * @throws InvalidArgumentException when the build has no such file
     */
    public function pathOf(string $file): string
    {
        if (! $this->serves($file)) {
            throw new InvalidArgumentException("The panel's build has no file {$file}.");
        }

        return $this->directory.'/'.$file;
    }
}
