<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\SiteId;

/**
 * What the RouteReader found for a site, a locale and a path (PRD 5.9 steps 1 and 2): the site,
 * whether it publishes in the locale, and the longest route of the site in the locale that is a
 * prefix of the path, or null when none is.
 */
#[Internal]
final readonly class SiteRoute
{
    public function __construct(
        public SiteId $site,
        public bool $localePublished,
        public ?RouteMatch $match,
    ) {}
}
