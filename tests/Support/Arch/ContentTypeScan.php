<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The rule of GUARDRAILS 2.4 that the kernel knows no content types: the kernel's modules never name
 * a type, a field or a select value of the workbench's fixture schema.
 *
 * The handles are read from every blueprint file below workbench/schema: the type's handle, the
 * handle of every field, groups included, and the value of every option of a select field. Each
 * starts with PREFIX, so a handle is never an ordinary word in code (`title`, `body`) and the rule
 * can match it in any spelling without false positives. The code is every file below the src
 * directory of each of the kernel's modules, read as text: code, strings, comments and doc blocks alike. A
 * handle matches anywhere in a line, in any case, with each underscore written as any run of `_`
 * and `-` or as nothing, so `fixture_article` matches `'fixture_article'`, `FixtureArticle`, `$fixtureArticle`,
 * `fixture-article` and `fixture_article_id`.
 */
final readonly class ContentTypeScan
{
    /**
     * The kernel's modules of GUARDRAILS 2.4, each a directory below packages/.
     *
     * @var list<string>
     */
    public const array PACKAGES = ['contracts', 'core', 'testkit', 'generators', 'http', 'cli', 'mcp'];

    public const string SCHEMA = 'workbench/schema';

    /**
     * The start of every handle of the fixture schema.
     */
    public const string PREFIX = 'fixture_';

    /**
     * @param  list<string>  $handles  sorted and unique
     * @param  list<string>  $files  relative to the root and sorted
     * @param  list<string>  $hits  one line per match: `<file>:<line>: <text>`
     */
    private function __construct(
        public array $handles,
        public array $files,
        public array $hits,
    ) {}

    /**
     * Scans the src directories of the kernel's modules below the root for the handles.
     *
     * @param  list<string>  $handles
     */
    public static function of(string $root, array $handles): self
    {
        $files = [];

        foreach (self::PACKAGES as $package) {
            $directory = "{$root}/packages/{$package}/src";

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        sort($files, SORT_STRING);
        $handles = self::normalized($handles);
        $hits = [];

        foreach ($files as $path) {
            $contents = file_get_contents($root.'/'.$path);

            if ($contents === false) {
                throw new RuntimeException("Cannot read {$path}.");
            }

            array_push($hits, ...self::hitsIn($path, $contents, $handles));
        }

        return new self($handles, $files, $hits);
    }

    /**
     * The handles of every blueprint file below the directory, sorted and unique.
     *
     * @return list<string>
     */
    public static function handlesBelow(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("The schema directory {$directory} does not exist.");
        }

        $handles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                array_push($handles, ...self::handlesOf(Yaml::parseFile($file->getPathname())));
            }
        }

        return self::normalized($handles);
    }

    /**
     * The handles of one parsed blueprint: the type's handle, the handles of its fields and of
     * the fields of its groups, and the values of the options of its select fields.
     *
     * @return list<string>
     */
    public static function handlesOf(mixed $blueprint): array
    {
        if (! is_array($blueprint)) {
            return [];
        }

        $handles = [];
        $handle = $blueprint['handle'] ?? null;
        $fields = $blueprint['fields'] ?? null;

        if (is_string($handle)) {
            $handles[] = $handle;
        }

        foreach (is_array($fields) ? $fields : [] as $field) {
            array_push($handles, ...self::handlesOf($field));
            $options = is_array($field) ? $field['options'] ?? null : null;

            if (! is_array($options) || ! array_is_list($options)) {
                continue;
            }

            foreach ($options as $option) {
                $value = is_array($option) ? $option['value'] ?? null : null;

                if (is_string($value)) {
                    $handles[] = $value;
                }
            }
        }

        return $handles;
    }

    /**
     * The handles that do not start with PREFIX followed by at least one character, which the
     * rule cannot match in every spelling without matching ordinary words.
     *
     * @param  list<string>  $handles
     * @return list<string>
     */
    public static function ordinary(array $handles): array
    {
        return array_values(array_filter(
            self::normalized($handles),
            static fn (string $handle): bool => ! str_starts_with($handle, self::PREFIX) || $handle === self::PREFIX,
        ));
    }

    /**
     * The lines of a file that name a handle, as `<file>:<line>: <text>`, where the text lists
     * each match as written.
     *
     * @param  list<string>  $handles
     * @return list<string>
     */
    public static function hitsIn(string $path, string $contents, array $handles): array
    {
        if ($handles === []) {
            return [];
        }

        $pattern = self::pattern($handles);
        $hits = [];

        foreach (explode("\n", $contents) as $offset => $line) {
            if (preg_match_all($pattern, $line, $matches) > 0) {
                $hits[] = sprintf('%s:%d: %s', $path, $offset + 1, implode(', ', $matches[0]));
            }
        }

        return $hits;
    }

    /**
     * Each handle anywhere, in any case, with each underscore as any run of `_` and `-` or as nothing. The
     * longest handles come first, so a handle that contains another is reported whole.
     *
     * @param  list<string>  $handles
     */
    public static function pattern(array $handles): string
    {
        $handles = self::normalized($handles);
        usort($handles, static fn (string $a, string $b): int => [strlen($b), $a] <=> [strlen($a), $b]);
        $words = array_map(
            static fn (string $handle): string => implode('[_-]*', array_map(
                static fn (string $part): string => preg_quote($part, '/'),
                explode('_', $handle),
            )),
            $handles,
        );

        return '/(?:'.implode('|', $words).')/i';
    }

    /**
     * @param  list<string>  $handles
     * @return list<string>
     */
    private static function normalized(array $handles): array
    {
        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }
}
