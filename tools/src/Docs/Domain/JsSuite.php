<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The JS unit suite of gate 5 (GUARDRAILS 10, decision D11 of the panel extension architecture):
 * the files the `unit` project of vitest.config.ts includes, as glob patterns relative to the root
 * of the repository, such as `js/*\/tests/**\/*.test.{js,ts,tsx}`. A TypeScript example on a page
 * runs as a test only when one of them includes it, as a PHP example runs only when a gate-5 suite
 * of phpunit.xml does (GateSuites).
 *
 * A pattern is matched as Vitest matches it: `**` crosses directories, `*` and `?` stay within one
 * segment, and `{a,b}` is one of its alternatives. A dot at the start of a segment is matched like
 * any other character.
 */
final readonly class JsSuite
{
    /**
     * The suffixes of a Vitest test file, which a TypeScript example has.
     *
     * @var list<string>
     */
    public const array SUFFIXES = ['.test.ts', '.test.tsx'];

    /** The name of the project in vitest.config.ts. */
    public const string PROJECT = 'unit';

    /**
     * The module specifiers a TypeScript example may not import, each a prefix: the panel's own app,
     * its router and page state, the headless primitives and the kit's own package, which an addon
     * reaches only through the SDK, @cboxdk/cms-panel, as the import map and the SDK's lint keep it.
     *
     * @var list<string>
     */
    public const array REFUSED_IMPORTS = ['@cboxdk/cms-panel-app', '@cboxdk/cms-ui-kit', '@inertiajs/', '@react-aria/', '@react-stately/', 'react-aria', 'react-stately'];

    /** A static import or export from a module, or a dynamic import(), with the module's specifier. */
    private const string IMPORT = '/(?:\bfrom\s*|\bimport\s*\(?\s*)([\'"])(?<specifier>[^\'"]+)\1/';

    /**
     * @param  list<string>  $includes  the project's include patterns, repo-relative
     */
    public function __construct(public array $includes) {}

    /**
     * A suite that includes nothing, for a tree without vitest.config.ts.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Whether the repo-relative path is a Vitest test file.
     */
    public static function isTestFile(string $path): bool
    {
        return array_any(self::SUFFIXES, static fn (string $suffix): bool => str_ends_with($path, $suffix));
    }

    /**
     * The modules a TypeScript example imports that an addon cannot: a specifier of REFUSED_IMPORTS,
     * or a relative path into js/, the workspaces of the panel and the kit, whose code an addon never
     * imports by path.
     *
     * @param  string  $path  the example's repo-relative path
     * @return list<string>
     */
    public static function refusedImports(string $path, string $source): array
    {
        preg_match_all(self::IMPORT, $source, $imports);
        $refused = [];

        foreach ($imports['specifier'] as $specifier) {
            $intoWorkspaces = str_starts_with($specifier, '.') && str_starts_with(self::resolve(dirname($path), $specifier).'/', 'js/');

            if ($intoWorkspaces || array_any(self::REFUSED_IMPORTS, static fn (string $prefix): bool => str_starts_with($specifier, $prefix))) {
                $refused[] = $specifier;
            }
        }

        return array_values(array_unique($refused));
    }

    /**
     * Whether the repo-relative file is one the project runs.
     */
    public function includes(string $path): bool
    {
        return array_any($this->includes, static fn (string $pattern): bool => preg_match(self::regex($pattern), $path) === 1);
    }

    /**
     * The patterns as a finding names them, or that there are none.
     */
    public function names(): string
    {
        return $this->includes === [] ? 'no include' : implode(', ', $this->includes);
    }

    /**
     * The repo-relative path a relative specifier names from a directory, `..` resolved.
     */
    private static function resolve(string $directory, string $specifier): string
    {
        $parts = [];

        foreach (explode('/', $directory.'/'.$specifier) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * The regular expression of a glob pattern, anchored to the whole path.
     */
    private static function regex(string $pattern): string
    {
        $pattern = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
        $regex = '';
        $length = strlen($pattern);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $pattern[$offset];

            if ($character === '*' && substr($pattern, $offset, 3) === '**/') {
                $regex .= '(?:.*/)?';
                $offset += 2;
            } elseif ($character === '*' && substr($pattern, $offset, 2) === '**') {
                $regex .= '.*';
                $offset++;
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } elseif ($character === '{' && ($close = strpos($pattern, '}', $offset)) !== false) {
                $alternatives = explode(',', substr($pattern, $offset + 1, $close - $offset - 1));
                $regex .= '(?:'.implode('|', array_map(static fn (string $alternative): string => preg_quote($alternative, '~'), $alternatives)).')';
                $offset = $close;
            } else {
                $regex .= preg_quote($character, '~');
            }
        }

        return '~\A'.$regex.'\z~';
    }
}
