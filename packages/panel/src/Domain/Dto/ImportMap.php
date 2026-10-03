<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Closure;
use InvalidArgumentException;

/**
 * The import map of a panel page (PRD 13.4), the one map the page has, because Firefox takes one
 * map per document. Its `imports` hand every module the panel shares, such as `react`, to the
 * entry of the panel's build that re-exports the panel's own copy, so an addon's module that
 * imports `react` runs on the React the panel runs on. Its `scopes` give the modules below each
 * addon's prefix the entry of every module an addon may not import, such as `@inertiajs/react`,
 * which throws PanelImportRefused before the addon runs. Its `integrity` gives the SHA-384 the
 * browser checks a module against before it runs it: every script of the panel's build, and every
 * file of an addon.
 *
 * Every URL is a path on the panel's own origin, such as `/cms/build/assets/react-1a2b3c.js`, and
 * an addon's prefix is a directory, ending in `/`, as an import map scope must be to match every
 * module below it. ImportMapJson writes the map into the page.
 */
#[Internal]
final readonly class ImportMap
{
    /** A path on the panel's origin: no scheme, no host, no whitespace, no quote or angle bracket. */
    public const string PATH_PATTERN = '~\A/(?!/)[^\s"\'<>\\\\]*\z~';

    /**
     * @param  array<string, string>  $imports  the URL of each shared module, by specifier
     * @param  array<string, string>  $refused  the URL of the entry of each module an addon may not import, by specifier
     * @param  array<string, array<string, string>>  $scopes  the mapping of each addon's prefix
     * @param  array<string, string>  $integrity  the SHA-384 of each module, `sha384-<base64>`, by URL
     *
     * @throws InvalidArgumentException when a URL is not a path on the panel's origin, a prefix is no directory or an integrity no SHA-384
     */
    public function __construct(
        public array $imports,
        public array $refused,
        public array $scopes = [],
        public array $integrity = [],
    ) {
        foreach ([...array_keys($imports), ...array_keys($refused)] as $specifier) {
            if (preg_match(PanelBuild::SPECIFIER_PATTERN, $specifier) !== 1) {
                throw new InvalidArgumentException("The import map names a module {$specifier}, which is not a bare module specifier.");
            }
        }

        foreach ([...array_values($imports), ...array_values($refused), ...array_keys($scopes), ...array_keys($integrity)] as $url) {
            $this->assertPath($url);
        }

        foreach ($scopes as $prefix => $mapping) {
            if (! str_ends_with($prefix, '/')) {
                throw new InvalidArgumentException("The import map's scope {$prefix} is not a directory: it must end in /.");
            }

            foreach ($mapping as $specifier => $url) {
                if (! array_key_exists($specifier, $refused) || $refused[$specifier] !== $url) {
                    throw new InvalidArgumentException("The import map's scope {$prefix} maps {$specifier} to {$url}, which is not the entry of a refused module.");
                }
            }
        }

        foreach ($integrity as $url => $hash) {
            if (preg_match(PanelBuild::INTEGRITY_PATTERN, $hash) !== 1) {
                throw new InvalidArgumentException("The integrity {$hash} of {$url} is not a SHA-384.");
            }
        }
    }

    /**
     * The map of a page of the build: its shared modules, and the SHA-384 of each of its scripts.
     * No addon has a scope yet.
     *
     * @param  Closure(string): string  $url  the URL of a file of the build
     */
    public static function of(PanelBuild $build, Closure $url): self
    {
        $integrity = [];

        foreach ($build->integrity as $file => $hash) {
            $integrity[$url($file)] = $hash;
        }

        ksort($integrity, SORT_STRING);

        return new self(
            self::sorted(array_map($url, $build->shared)),
            self::sorted(array_map($url, $build->refused)),
            [],
            $integrity,
        );
    }

    /**
     * The map with a scope for an addon's modules below the prefix, which gives them the entry of
     * every module an addon may not import, and the SHA-384 of each of the addon's files, by URL.
     *
     * @param  array<string, string>  $integrity
     *
     * @throws InvalidArgumentException when the addon already has a scope, or a file lies outside its prefix
     */
    public function withAddonScope(string $prefix, array $integrity): self
    {
        if (array_key_exists($prefix, $this->scopes)) {
            throw new InvalidArgumentException("The import map already has a scope for {$prefix}.");
        }

        foreach (array_keys($integrity) as $url) {
            if (! str_starts_with($url, $prefix)) {
                throw new InvalidArgumentException("The addon's file {$url} lies outside its prefix {$prefix}.");
            }
        }

        $scopes = $this->scopes;
        $scopes[$prefix] = $this->refused;
        ksort($scopes, SORT_STRING);

        return new self($this->imports, $this->refused, $scopes, self::sorted([...$this->integrity, ...$integrity]));
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private static function sorted(array $map): array
    {
        ksort($map, SORT_STRING);

        return $map;
    }

    private function assertPath(string $url): void
    {
        if (preg_match(self::PATH_PATTERN, $url) !== 1) {
            throw new InvalidArgumentException("The import map names {$url}, which is not a path on the panel's origin.");
        }
    }
}
