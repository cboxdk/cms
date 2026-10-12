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
 * Retires a locale of a registered site (PRD 5.9, 11.14), version 1 of site.remove_locale: the
 * site, the version of it the caller read, and the locale. In one changeset the site stops
 * publishing in the locale, and site.locale_removed tells about it; the routes in the locale stay
 * retired with it, so site.add_locale brings them back.
 *
 * It is decided on the site's root node (PRD 5.10): the actor needs site.remove_locale on the root
 * in the locale, and a root its grants do not reach is unauthorized. While a placement locale in
 * the locale anywhere on the site is not withdrawn, the command is site_locale_in_use, with the
 * count; the plan reads the site's use of the locale as an aggregate, so a placement created in
 * the locale between the read and the commit is version_conflict, never an orphan. A locale the
 * site does not publish in, and the site's last locale, are validation_failed; a site at another
 * version than the caller read, or one that does not exist, is version_conflict.
 * cms:sites:sync runs it as the installation operator for each locale the site publishes in and
 * cbox-cms.sites no longer configures.
 */
#[CommandName('site.remove_locale', version: 1)]
#[Experimental]
final readonly class RemoveSiteLocale implements ExpectsVersions
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
