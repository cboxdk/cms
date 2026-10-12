<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Adds a locale to a registered site (PRD 5.9, 11.14), version 1 of site.add_locale: the site, the
 * version of it the caller read, and the locale. In one changeset the site publishes in the locale,
 * with the route `/` to its root node in the locale when it has none there, and site.locale_added
 * tells about it. A locale the site retired comes back with the routes it had.
 *
 * It is decided on the site's root node (PRD 5.10): the actor needs site.add_locale on the root in
 * the locale, and a root its grants do not reach is unauthorized. A locale the site publishes in
 * already is validation_failed; a site at another version than the caller read, or one that does
 * not exist, is version_conflict. cms:sites:sync runs it as the installation operator for each
 * locale cbox-cms.sites configures and the site lacks.
 */
#[CommandName('site.add_locale', version: 1)]
#[Experimental]
final readonly class AddSiteLocale implements ExpectsVersions
{
    public function __construct(
        public SiteId $site,
        public AggregateVersion $version,
        public Locale $locale,
    ) {}

    /**
     * The site, at the version read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->site, $this->version));
    }
}
