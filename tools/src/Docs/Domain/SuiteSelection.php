<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The files one phpunit.xml test suite runs: its directories and files, less its excludes.
 */
final readonly class SuiteSelection
{
    /**
     * @param  list<SuiteDirectory>  $directories
     * @param  list<string>  $files  repo-relative
     * @param  list<string>  $excludes  repo-relative directories or files, whose segments may be globs
     */
    public function __construct(
        public string $name,
        public array $directories,
        public array $files = [],
        public array $excludes = [],
    ) {}

    public function includes(string $path): bool
    {
        if (array_any($this->excludes, static fn (string $exclude): bool => SuiteDirectory::below($exclude, $path, true))) {
            return false;
        }

        return in_array($path, $this->files, true)
            || array_any($this->directories, static fn (SuiteDirectory $directory): bool => $directory->includes($path));
    }
}
