<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUseRef;
use Override;

/**
 * What site.add_locale and site.remove_locale read (PRD 6.2 phase 1): the site with its locales
 * and its root node, null when the installation has no such site, and, for a removal, the site's
 * use of the locale as an aggregate, with the placements counted, which the command expects to be
 * none. The kernel checks at commit that the site is still at the version read and the locale
 * still unused, so a change of the site or a placement created in the locale meanwhile is
 * version_conflict.
 */
#[Internal]
final readonly class SiteLocaleAggregates implements Aggregates
{
    public function __construct(
        public SiteId $site,
        public ?StoredSite $storedSite,
        public Locale $locale,
        public ?SiteLocaleUseRef $use = null,
        public int $placements = 0,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->site, $this->storedSite?->version)];

        if ($this->use instanceof SiteLocaleUseRef) {
            $reads[] = new ReadVersion($this->use, $this->placements > 0 ? AggregateVersion::first() : null);
        }

        return new ReadVersions(...$reads);
    }

    /**
     * The site's root node, in the locale (PRD 5.10): the authorizer allows the command only through
     * a role that reaches the root there, and the installation operator only through the maintenance
     * authorizer; anywhere when the site read as absent, which the commit's version check rejects.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->storedSite instanceof StoredSite
            ? AuthorizationScope::on(new AuthorizationTarget($this->storedSite->root, $this->locale))
            : AuthorizationScope::anywhere();
    }
}
