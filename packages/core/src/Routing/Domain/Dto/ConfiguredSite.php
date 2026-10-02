<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;

/**
 * A site as the configuration serves it (PRD 5.9, 8.10 point 7, 11.14): its handle, the origin its
 * canonical URLs are built from, the locales it publishes in, at least one and each once, which
 * cms:sites:sync registers it with, and every host that resolves to it, the origin's host first and
 * each once.
 */
#[Experimental]
final readonly class ConfiguredSite
{
    /** @var list<Host> the origin's host first, each once */
    public array $hosts;

    /**
     * @param  list<Locale>  $locales  the locales the site publishes in, in the configured order
     * @param  list<Host>  $aliases  other hosts that resolve to the site
     *
     * @throws InvalidRoutingValue for no locale, or a locale named twice
     */
    public function __construct(
        public SiteHandle $handle,
        public SiteOrigin $origin,
        public array $locales,
        array $aliases = [],
    ) {
        if ($locales === []) {
            throw InvalidRoutingValue::siteWithoutLocales($handle);
        }

        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                throw InvalidRoutingValue::repeatedSiteLocale($handle, $locale);
            }

            $seen[$locale->value] = true;
        }

        $hosts = [$origin->host->value => $origin->host];

        foreach ($aliases as $alias) {
            $hosts[$alias->value] ??= $alias;
        }

        $this->hosts = array_values($hosts);
    }

    public function serves(Host $host): bool
    {
        return array_any($this->hosts, static fn (Host $served): bool => $served->equals($host));
    }
}
