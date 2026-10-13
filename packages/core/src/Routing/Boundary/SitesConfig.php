<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackChain;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the configured sites from `cbox-cms.sites` (PRD 5.9 step 1, 8.10 point 7, 11.14, 17): a map
 * from a site's handle, as the sites table holds it, to where it is served, the locales it
 * publishes in and the chain a page falls back along (PRD 10.3):
 *
 *     'sites' => [
 *         'north' => [
 *             'origin' => 'https://north.example',   // the scheme and host of its canonical URLs
 *             'locales' => ['da', 'en'],             // the locales it publishes in, at least one
 *             'hosts' => ['www.north.example'],      // other hosts that resolve to it, default []
 *             'fallback' => ['da', 'en'],            // the fallback chain, locales of the site, default []
 *         ],
 *     ],
 *
 * The origin's host resolves to the site too. A host belongs to one site. Only a configured host
 * resolves, and a canonical URL is built from its site's origin, never from a request's host. A
 * read in a locale of the fallback chain falls back along the locales after it (FallbackChain).
 * cms:sites:sync registers each configured site the database lacks with its locales, and
 * cms:doctor's doctor.config fails while the setting is invalid.
 */
#[Internal]
final readonly class SitesConfig
{
    public const string CONFIG_KEY = 'cbox-cms.sites';

    /**
     * @throws InvalidArgumentException when the setting is not a map of handles to an origin, a list
     *                                  of locales, a list of hosts and a fallback chain, a host is
     *                                  configured for two sites, or a fallback chain names a locale
     *                                  twice or one the site does not publish in
     */
    public static function read(Repository $config): SiteHosts
    {
        $sites = $config->get(self::CONFIG_KEY, []);

        if (! is_array($sites)) {
            throw self::invalid(self::CONFIG_KEY, 'a map from site handles to their origin and hosts', $sites);
        }

        $configured = [];

        foreach ($sites as $handle => $site) {
            $configured[] = self::site(is_string($handle) ? $handle : (string) $handle, $site);
        }

        try {
            return new SiteHosts($configured);
        } catch (InvalidRoutingValue $invalid) {
            throw new InvalidArgumentException(sprintf('The setting %s is invalid: %s', self::CONFIG_KEY, $invalid->getMessage()), $invalid->getCode(), previous: $invalid);
        }
    }

    private static function site(string $handle, mixed $site): ConfiguredSite
    {
        $key = self::CONFIG_KEY.'.'.$handle;

        if (! is_array($site)) {
            throw self::invalid($key, 'a map with origin and hosts', $site);
        }

        $origin = $site['origin'] ?? null;
        $locales = $site['locales'] ?? null;
        $hosts = $site['hosts'] ?? [];
        $fallback = $site['fallback'] ?? [];

        if (! is_string($origin)) {
            throw self::invalid($key.'.origin', 'a string such as "https://example.dk"', $origin);
        }

        if (! is_array($locales) || ! array_is_list($locales) || $locales === []) {
            throw self::invalid($key.'.locales', 'a list of at least one locale such as ["da", "en"]', $locales);
        }

        $tags = [];

        foreach ($locales as $locale) {
            $tags[] = is_string($locale) ? $locale : throw self::invalid($key.'.locales', 'a list of at least one locale such as ["da", "en"]', $locale);
        }

        if (! is_array($hosts) || ! array_is_list($hosts)) {
            throw self::invalid($key.'.hosts', 'a list of host names', $hosts);
        }

        $names = [];

        foreach ($hosts as $host) {
            $names[] = is_string($host) ? $host : throw self::invalid($key.'.hosts', 'a list of host names', $host);
        }

        if (! is_array($fallback) || ! array_is_list($fallback)) {
            throw self::invalid($key.'.fallback', 'a list of locales of the site such as ["de-AT", "de", "en"]', $fallback);
        }

        $chain = [];

        foreach ($fallback as $locale) {
            $chain[] = is_string($locale) ? $locale : throw self::invalid($key.'.fallback', 'a list of locales of the site such as ["de-AT", "de", "en"]', $locale);
        }

        try {
            return new ConfiguredSite(
                new SiteHandle($handle),
                new SiteOrigin($origin),
                array_map(static fn (string $tag): Locale => new Locale($tag), $tags),
                array_map(static fn (string $host): Host => new Host($host), $names),
                new FallbackChain(array_map(static fn (string $tag): Locale => new Locale($tag), $chain)),
            );
        } catch (InvalidRoutingValue|InvalidContentValue $invalid) {
            throw new InvalidArgumentException(sprintf('The setting %s is invalid: %s', $key, $invalid->getMessage()), $invalid->getCode(), previous: $invalid);
        }
    }

    private static function invalid(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The setting %s must be %s; it is %s.', $key, $expected, get_debug_type($value)));
    }
}
