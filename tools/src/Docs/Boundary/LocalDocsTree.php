<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\DocsTree;
use Cbox\Cms\Tooling\Docs\Domain\Page;
use Cbox\Cms\Tooling\Docs\Domain\PageParser;
use Cbox\Cms\Tooling\Docs\Domain\PhpFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Reads the documentation tree below a root directory, the repository or a scratch copy of it:
 * the PHP files of packages/<package>/src, the JSON schemas and the pages, the PHP files below
 * examples/, and the gate-5 suites of the root's phpunit.xml. Every list is sorted by path, and
 * symlinked directories are not followed.
 */
final readonly class LocalDocsTree
{
    public static function read(string $root): DocsTree
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new UnexpectedValueException("{$root} is not a directory.");
        }

        $packages = glob($real.'/packages/*', GLOB_ONLYDIR) ?: [];
        sort($packages);
        $files = new LocalRepositoryFiles($real);

        $sources = [];
        $schemas = [];
        $pages = [];

        foreach ($packages as $package) {
            array_push($sources, ...self::files($real, $package.'/src', '.php'));
            array_push($schemas, ...self::files($real, $package.'/resources/schemas', '.json'));
            array_push($pages, ...self::files($real, $package.'/docs', '.md'), ...self::files($real, $package.'/resources/schemas', '.md'));
        }

        return new DocsTree(
            array_map(static fn (string $path): PhpFile => PhpTokens::read($path, $files->contents($path) ?? ''), $sources),
            $schemas,
            array_map(static fn (string $path): Page => PageParser::parse($path, $files->contents($path) ?? ''), $pages),
            array_map(static fn (string $path): PhpFile => PhpTokens::read($path, $files->contents($path) ?? ''), self::files($real, $real.'/'.DocsAudit::EXAMPLES, '.php')),
            PhpunitGateSuites::read($real.'/phpunit.xml'),
            $files,
        );
    }

    /**
     * The repo-relative paths of the regular files below the directory that end in the extension.
     *
     * @return list<string>
     */
    private static function files(string $root, string $directory, string $extension): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }
}
