<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Docs\Boundary\PhpunitGateSuites;
use Cbox\Cms\Tooling\Docs\Domain\SuiteDirectory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The test files of phpunit.xml's test suites, relative to the checkout and sorted: the files
 * below each suite's directories (their segments may be globs, such as packages/<package>/tests)
 * and its files, less its excludes, as PhpunitGateSuites reads them and SuiteSelection decides.
 */
final readonly class SuiteTestFiles
{
    /**
     * The source of each test file of the suites, by path, sorted by path.
     *
     * @param  list<string>  $suites
     * @return array<string, string>
     */
    public static function contents(string $root, array $suites): array
    {
        $contents = [];

        foreach (self::of($root, $suites) as $file) {
            $source = file_get_contents($root.'/'.$file);

            if ($source !== false) {
                $contents[$file] = $source;
            }
        }

        return $contents;
    }

    /**
     * @param  list<string>  $suites
     * @return list<string>
     */
    public static function of(string $root, array $suites): array
    {
        $selection = PhpunitGateSuites::read($root.'/phpunit.xml', $suites);
        $candidates = [];

        foreach ($selection->suites as $suite) {
            foreach ($suite->files as $file) {
                $candidates[$file] = true;
            }

            foreach ($suite->directories as $directory) {
                foreach (self::below($root, $directory) as $file) {
                    $candidates[$file] = true;
                }
            }
        }

        $files = array_values(array_filter(
            array_map(strval(...), array_keys($candidates)),
            static fn (string $file): bool => $selection->includes($file) && is_file($root.'/'.$file),
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The files at any depth below the directories that the directory's pattern names.
     *
     * @return list<string>
     */
    private static function below(string $root, SuiteDirectory $directory): array
    {
        $files = [];

        foreach (glob($root.'/'.$directory->directory, GLOB_ONLYDIR) ?: [] as $found) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($found, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        return $files;
    }
}
