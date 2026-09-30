<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;

/**
 * The configured sites (PRD 5.9 step 1, 8.10 point 7): which site each host resolves to, and the
 * origin each site's canonical URLs are built from. Sites and hosts are configuration kept in git
 * (PRD 17), never taken from a request. A host belongs to at most one site, and a site is
 * configured once.
 */
#[Internal]
final readonly class SiteHosts
{
    /** @var list<ConfiguredSite> */
    public array $sites;

    /**
     * @param  list<ConfiguredSite>  $sites
     *
     * @throws InvalidRoutingValue for a site configured twice or a host of two sites
     */
    public function __construct(array $sites = [])
    {
        $handles = [];
        $hosts = [];

        foreach ($sites as $site) {
            if (isset($handles[$site->handle->value])) {
                throw InvalidRoutingValue::duplicateSite($site->handle);
            }

            $handles[$site->handle->value] = true;

            foreach ($site->hosts as $host) {
                if (isset($hosts[$host->value])) {
                    throw InvalidRoutingValue::duplicateHost($host, $hosts[$host->value], $site->handle);
                }

                $hosts[$host->value] = $site->handle;
            }
        }

        $this->sites = $sites;
    }

    /**
     * The site that serves the host, or null when no configured site does.
     */
    public function serving(Host $host): ?ConfiguredSite
    {
        return array_find($this->sites, static fn (ConfiguredSite $site): bool => $site->serves($host));
    }

    /**
     * The configured site with the handle, or null.
     */
    public function named(SiteHandle $handle): ?ConfiguredSite
    {
        return array_find($this->sites, static fn (ConfiguredSite $site): bool => $site->handle->equals($handle));
    }
}
