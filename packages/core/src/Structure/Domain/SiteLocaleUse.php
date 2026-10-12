<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;

/**
 * Reads how many placements a site still has in a locale (PRD 5.9, 11.14): the released placement
 * locales in the locale below the site's root that are not withdrawn, counted past the actor's
 * regions, because a locale a placement uses anywhere on the site may not be retired, whoever asks.
 * It runs in the command transaction under the call's actor context, in one statement however
 * many placements the site has (GUARDRAILS 4.1). It takes no lock; the commit locks the site's use
 * of the locale and checks it again.
 */
#[Internal]
interface SiteLocaleUse
{
    public function placements(SiteId $site, Locale $locale): int;
}
