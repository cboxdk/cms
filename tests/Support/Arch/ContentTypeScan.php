<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The rule of GUARDRAILS 2.4 that the kernel knows no content types: the core packages never name
 * a type, a field or a select value of the workbench's fixture schema.
 *
 * The handles are read from every blueprint file below workbench/schema: the type's handle, the
 * handle of every field, groups included, and the value of every option of a select field. The
 * code is every file below the src directory of each core package. A PHP file is read token by
 * token: string literals, the content of heredocs, nowdocs and interpolated strings, identifiers
 * and qualified names, but no comments, so a doc block may use an example. Any other file is read
 * as text. A handle matches as a whole word in any case, where a word character is a letter, a
 * digit or an underscore, so `article` matches `'article'` and `Article`, but not `articles` or
 * `article_id`.
 */
final readonly class ContentTypeScan
{
    /**
     * The core packages of GUARDRAILS 2.4.
     *
     * @var list<string>
     */
    public const array PACKAGES = ['contracts', 'core', 'testkit', 'generators', 'http', 'cli'];

    public const string SCHEMA = 'workbench/schema';

    /**
     * The tokens of a PHP file whose text is code or data rather than a comment.
     *
     * @var list<int>
     */
    public const array TOKENS = [
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_INLINE_HTML,
    ];

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
     * Scans the src directories of the core packages below the root for the handles.
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
     * The lines of a file that name a handle, as `<file>:<line>: <text>`. A PHP file is read
     * token by token and its comments are left out.
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
        $found = [];

        foreach (self::fragments($path, $contents) as [$line, $text]) {
            foreach (explode("\n", $text) as $offset => $part) {
                if (preg_match_all($pattern, $part, $matches) > 0) {
                    $found[$line + $offset] = [...$found[$line + $offset] ?? [], ...$matches[0]];
                }
            }
        }

        ksort($found);
        $hits = [];

        foreach ($found as $line => $words) {
            $hits[] = sprintf('%s:%d: %s', $path, $line, implode(', ', $words));
        }

        return $hits;
    }

    /**
     * Whole words in any case: a word character is a letter, a digit or an underscore.
     *
     * @param  list<string>  $handles
     */
    public static function pattern(array $handles): string
    {
        $words = array_map(static fn (string $handle): string => preg_quote($handle, '/'), $handles);

        return '/(?<![A-Za-z0-9_])(?:'.implode('|', $words).')(?![A-Za-z0-9_])/i';
    }

    /**
     * The text of a file to match, each piece with the line it starts on.
     *
     * @return list<array{int, string}>
     */
    private static function fragments(string $path, string $contents): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return [[1, $contents]];
        }

        $fragments = [];

        foreach (PhpToken::tokenize($contents) as $token) {
            if (in_array($token->id, self::TOKENS, true)) {
                $fragments[] = [$token->line, $token->text];
            }
        }

        return $fragments;
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
