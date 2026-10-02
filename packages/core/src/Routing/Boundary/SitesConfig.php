<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the configured sites from `cbox-cms.sites` (PRD 5.9 step 1, 8.10 point 7, 11.14, 17): a map
 * from a site's handle, as the sites table holds it, to where it is served and the locales it
 * publishes in:
 *
 *     'sites' => [
 *         'north' => [
 *             'origin' => 'https://north.example',   // the scheme and host of its canonical URLs
 *             'locales' => ['da', 'en'],             // the locales it publishes in, at least one
 *             'hosts' => ['www.north.example'],      // other hosts that resolve to it, default []
 *         ],
 *     ],
 *
 * The origin's host resolves to the site too. A host belongs to one site. Only a configured host
 * resolves, and a canonical URL is built from its site's origin, never from a request's host.
 * cms:sites:sync registers each configured site the database lacks with its locales.
 */
#[Internal]
final readonly class SitesConfig
{
    public const string CONFIG_KEY = 'cbox-cms.sites';

    /**
     * @throws InvalidArgumentException when the setting is not a map of handles to an origin, a list
     *                                  of locales and a list of hosts, or a host is configured for two
     *                                  sites
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

        try {
            return new ConfiguredSite(
                new SiteHandle($handle),
                new SiteOrigin($origin),
                array_map(static fn (string $tag): Locale => new Locale($tag), $tags),
                array_map(static fn (string $host): Host => new Host($host), $names),
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
