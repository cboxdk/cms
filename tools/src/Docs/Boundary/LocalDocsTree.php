<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\DocsLayout;
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
 * the PHP files of packages/<package>/src and the JSON schemas of packages/<package>/resources/schemas,
 * every file and directory below docs/ with its Markdown pages, README.md, the Markdown files below
 * packages/, the PHP files below examples/, and the gate-5 suites of the root's phpunit.xml. Every
 * list is sorted by path, and symlinked directories are not followed.
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

        foreach ($packages as $package) {
            array_push($sources, ...self::files($real, $package.'/src', '.php'));
            array_push($schemas, ...self::files($real, $package.'/resources/schemas', '.json'));
        }

        $docs = $real.'/'.DocsLayout::ROOT;
        $docsFiles = self::files($real, $docs, '');
        $page = static fn (string $path): Page => PageParser::parse($path, $files->contents($path) ?? '');
        $readme = $files->contents(DocsLayout::README);

        return new DocsTree(
            array_map(static fn (string $path): PhpFile => PhpTokens::read($path, $files->contents($path) ?? ''), $sources),
            $schemas,
            array_map($page, array_values(array_filter($docsFiles, static fn (string $path): bool => str_ends_with($path, '.md')))),
            array_map(static fn (string $path): PhpFile => PhpTokens::read($path, $files->contents($path) ?? ''), self::files($real, $real.'/'.DocsAudit::EXAMPLES, '.php')),
            PhpunitGateSuites::read($real.'/phpunit.xml'),
            $files,
            $readme === null ? null : PageParser::parse(DocsLayout::README, $readme),
            $docsFiles,
            self::directories($real, $docs),
            self::files($real, $real.'/packages', '.md'),
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

    /**
     * The repo-relative paths of the directories below the directory, not the directory itself.
     *
     * @return list<string>
     */
    private static function directories(string $root, string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file instanceof SplFileInfo && $file->isDir() && ! $file->isLink()) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }
}
