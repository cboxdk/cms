<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What cms:sites:sync did with one configured site (PRD 11.14), or would do in a dry run.
 */
#[Internal]
enum SiteSyncOutcome: string
{
    /** The database lacked the site, and site.register committed it. */
    case Registered = 'registered';

    /** The site is registered with the configured locales; nothing was written. */
    case Unchanged = 'unchanged';

    /** The site was registered with other locales, and every locale change committed. */
    case Reconciled = 'reconciled';

    /** The pipeline rejected site.register, or one of the site's locale changes; the rest of the site is as the lines say. */
    case Rejected = 'rejected';

    /**
     * Whether the site is registered with the configured locales after the sync.
     */
    public function synced(): bool
    {
        return $this !== self::Rejected;
    }
}
