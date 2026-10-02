<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Host;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * `cbox-cms.sites` (PRD 5.9, 8.10 point 7, 11.14): a map of site handles to an origin, the locales
 * the site publishes in and other hosts.
 */

it('reads the configured sites with their origins, locales and hosts, and none by default', function (): void {
    $sites = SitesConfig::read(new Repository(['cbox-cms' => ['sites' => [
        'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'EN-gb'], 'hosts' => ['www.north.example']],
        'south' => ['origin' => 'http://south.example:8080', 'locales' => ['da']],
    ]]]));

    expect(array_map(static fn (ConfiguredSite $site): string => $site->handle->value.' '.$site->origin->value.' '.implode(',', array_map(static fn (Locale $locale): string => $locale->value, $site->locales)).' '.implode(',', array_map(static fn (Host $host): string => $host->value, $site->hosts)), $sites->sites))
        ->toBe(['north https://north.example da,en-GB north.example,www.north.example', 'south http://south.example:8080 da south.example:8080'])
        ->and(SitesConfig::read(new Repository([]))->sites)->toBe([]);
});

it('refuses a setting that is not a map of handles to an origin, a list of locales and a list of hosts', function (mixed $sites, string $message): void {
    expect(static fn (): mixed => SitesConfig::read(new Repository(['cbox-cms' => ['sites' => $sites]])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a map' => ['north', 'The setting cbox-cms.sites must be a map from site handles to their origin and hosts; it is string.'],
    'a site that is not a map' => [['north' => 'https://north.example'], 'The setting cbox-cms.sites.north must be a map with origin and hosts; it is string.'],
    'no origin' => [['north' => ['locales' => ['da'], 'hosts' => []]], 'The setting cbox-cms.sites.north.origin must be a string such as "https://example.dk"; it is null.'],
    'no locales' => [['north' => ['origin' => 'https://north.example']], 'The setting cbox-cms.sites.north.locales must be a list of at least one locale such as ["da", "en"]; it is null.'],
    'an empty list of locales' => [['north' => ['origin' => 'https://north.example', 'locales' => []]], 'The setting cbox-cms.sites.north.locales must be a list of at least one locale such as ["da", "en"]; it is array.'],
    'locales not a list' => [['north' => ['origin' => 'https://north.example', 'locales' => 'da']], 'The setting cbox-cms.sites.north.locales must be a list of at least one locale such as ["da", "en"]; it is string.'],
    'a locale not a string' => [['north' => ['origin' => 'https://north.example', 'locales' => [45]]], 'The setting cbox-cms.sites.north.locales must be a list of at least one locale such as ["da", "en"]; it is int.'],
    'a bad locale' => [['north' => ['origin' => 'https://north.example', 'locales' => ['danish']]], 'The setting cbox-cms.sites.north is invalid: '],
    'a locale twice' => [['north' => ['origin' => 'https://north.example', 'locales' => ['da', 'DA']]], 'The setting cbox-cms.sites.north is invalid: The site "north" names the locale da twice.'],
    'hosts not a list' => [['north' => ['origin' => 'https://north.example', 'locales' => ['da'], 'hosts' => 'www.north.example']], 'The setting cbox-cms.sites.north.hosts must be a list of host names; it is string.'],
    'a host not a string' => [['north' => ['origin' => 'https://north.example', 'locales' => ['da'], 'hosts' => [8080]]], 'The setting cbox-cms.sites.north.hosts must be a list of host names; it is int.'],
    'a bad handle' => [['North' => ['origin' => 'https://north.example', 'locales' => ['da']]], 'The setting cbox-cms.sites.North is invalid: A site handle'],
    'a bad origin' => [['north' => ['origin' => 'north.example', 'locales' => ['da']]], 'The setting cbox-cms.sites.north is invalid: A site origin'],
    'a host of two sites' => [[
        'north' => ['origin' => 'https://north.example', 'locales' => ['da']],
        'south' => ['origin' => 'https://south.example', 'locales' => ['da'], 'hosts' => ['north.example']],
    ], 'The setting cbox-cms.sites is invalid: The host "north.example" is configured for both the sites "north" and "south"'],
]);
