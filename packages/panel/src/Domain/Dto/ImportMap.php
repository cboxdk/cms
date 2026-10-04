<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Closure;
use InvalidArgumentException;

/**
 * The import map of a panel page (PRD 13.4), the one map the page has, because Firefox takes one
 * map per document. Its `imports` hand every module the panel shares, such as `react` and the
 * SDK's subpaths, to the entry of the panel's build that re-exports the panel's own copy, so an
 * addon's module that imports `react` runs on the React the panel runs on, and the entry module
 * of each addon's bundle to the bare specifier the host imports it by, `cms-addons/<namespace>`
 * (ADDON_SPECIFIER). Its `scopes` give the modules below each addon's prefix the entry of every
 * module an addon may not import, such as `@inertiajs/react`, which throws PanelImportRefused
 * before the addon runs. Its `integrity` gives the SHA-384 the browser checks a module against
 * before it runs it: every script of the panel's build, and every script of an addon.
 *
 * Every URL is a path on the panel's own origin, such as `/cms/build/assets/react-1a2b3c.js`, and
 * an addon's prefix is a directory, ending in `/`, as an import map scope must be to match every
 * module below it. The one exception is an addon loaded from a dev server (DevServer): its entry
 * and the shared modules the server's import analysis names are URLs on the server's loopback
 * origin, with no integrity. ImportMapJson writes the map into the page.
 */
#[Internal]
final readonly class ImportMap
{
    /** A path on the panel's origin: no scheme, no host, no whitespace, no quote or angle bracket. */
    public const string PATH_PATTERN = '~\A/(?!/)[^\s"\'<>\\\\]*\z~';

    /** A URL on a dev server's loopback origin over http. */
    public const string DEV_URL_PATTERN = '~\Ahttp://(?:localhost|127\.0\.0\.1|\[::1\])(?::[1-9][0-9]{0,4})?/[^\s"\'<>\\\\]*\z~';

    /** The bare specifier the host imports an addon's entry module by, before its namespace. */
    public const string ADDON_SPECIFIER = 'cms-addons/';

    /**
     * @param  array<string, string>  $imports  the URL of each shared module and each addon's entry, by specifier
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
            if (preg_match(PanelBuild::SPECIFIER_PATTERN, $specifier) !== 1 && preg_match(self::DEV_URL_PATTERN, $specifier) !== 1) {
                throw new InvalidArgumentException("The import map names a module {$specifier}, which is not a bare module specifier.");
            }
        }

        foreach ([...array_values($imports), ...array_values($refused), ...array_keys($scopes)] as $url) {
            $this->assertUrl($url);
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
            $this->assertPath($url);

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
     * The bare specifier the host imports an addon's entry module by.
     */
    public static function addonSpecifier(AddonNamespace $addon): string
    {
        return self::ADDON_SPECIFIER.$addon->value;
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
     * The map with an addon's bundle: its entry module under the addon's specifier, a scope for
     * its files below the prefix and the SHA-384 of each of its scripts, by URL.
     *
     * @param  array<string, string>  $integrity  the SHA-384 of each script of the addon, by URL
     *
     * @throws InvalidArgumentException when the addon is in the map already, or the entry lies outside its prefix
     */
    public function withAddon(AddonNamespace $addon, string $prefix, string $entry, array $integrity): self
    {
        $specifier = self::addonSpecifier($addon);

        if (array_key_exists($specifier, $this->imports)) {
            throw new InvalidArgumentException("The import map already has the addon {$addon->value}.");
        }

        if (! str_starts_with($entry, $prefix)) {
            throw new InvalidArgumentException("The addon's entry {$entry} lies outside its prefix {$prefix}.");
        }

        $scoped = $this->withAddonScope($prefix, $integrity);

        return new self(self::sorted([...$scoped->imports, $specifier => $entry]), $scoped->refused, $scoped->scopes, $scoped->integrity);
    }

    /**
     * The map with an addon loaded from a dev server instead of its bundle: its entry module on
     * the server under the addon's specifier, and each shared module as the server's import
     * analysis names it, on the server's origin, mapped to the panel's own copy.
     *
     * @throws InvalidArgumentException when the addon is in the map already
     */
    public function withDevServer(DevServer $server): self
    {
        $specifier = self::addonSpecifier($server->addon);

        if (array_key_exists($specifier, $this->imports)) {
            throw new InvalidArgumentException("The import map already has the addon {$server->addon->value}.");
        }

        $imports = $this->imports;
        $imports[$specifier] = $server->entryUrl();

        foreach ($this->imports as $shared => $url) {
            if (! str_starts_with($shared, self::ADDON_SPECIFIER) && preg_match(self::DEV_URL_PATTERN, $shared) !== 1) {
                $imports[$server->sharedUrl($shared)] = $url;
            }
        }

        return new self(self::sorted($imports), $this->refused, $this->scopes, $this->integrity);
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

    private function assertUrl(string $url): void
    {
        if (preg_match(self::PATH_PATTERN, $url) !== 1 && preg_match(self::DEV_URL_PATTERN, $url) !== 1) {
            throw new InvalidArgumentException("The import map names {$url}, which is not a path on the panel's origin or a URL on a dev server.");
        }
    }
}
