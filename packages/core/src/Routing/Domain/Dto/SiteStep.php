<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;

/**
 * Step 1 of a resolution (PRD 5.9): the host to a site. The handle of the configured site that
 * serves the host, or null when none does; the site with that handle, or null when there is none;
 * and whether the site publishes in the locale, false when no site was found.
 */
#[Experimental]
final readonly class SiteStep
{
    public function __construct(
        public Host $host,
        public Locale $locale,
        public ?SiteHandle $handle,
        public ?SiteId $site,
        public bool $localePublished,
    ) {}
}
