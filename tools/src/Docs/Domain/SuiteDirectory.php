<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A `<directory>` of a phpunit.xml test suite: a repo-relative directory whose segments may be
 * glob patterns, such as packages/<package>/tests, and the prefix and suffix of the file names it takes.
 */
final readonly class SuiteDirectory
{
    /** PHPUnit's default suffix of a directory's test files. */
    public const string SUFFIX = 'Test.php';

    public function __construct(
        public string $directory,
        public string $prefix = '',
        public string $suffix = self::SUFFIX,
    ) {}

    /**
     * Whether the repo-relative file is below the directory, at any depth, and named as the suite
     * takes files.
     */
    public function includes(string $path): bool
    {
        $name = basename($path);

        return str_starts_with($name, $this->prefix)
            && str_ends_with($name, $this->suffix)
            && self::below($this->directory, $path, false);
    }

    /**
     * Whether the path is below the pattern at any depth, or with $orSame also the pattern's own
     * directory or file. Each segment of the pattern matches one segment of the path, as a glob.
     */
    public static function below(string $pattern, string $path, bool $orSame): bool
    {
        $patternSegments = self::segments($pattern);
        $pathSegments = self::segments($path);

        if (count($pathSegments) < count($patternSegments) + ($orSame ? 0 : 1)) {
            return false;
        }

        return array_all($patternSegments, fn (string $segment, $index): bool => fnmatch($segment, $pathSegments[$index], FNM_PERIOD));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $path = str_starts_with($path, './') ? substr($path, 2) : $path;

        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
    }
}
