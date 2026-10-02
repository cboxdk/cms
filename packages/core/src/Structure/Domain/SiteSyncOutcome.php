<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What cms:sites:sync did with one configured site (PRD 11.14).
 */
#[Internal]
enum SiteSyncOutcome: string
{
    /** The database lacked the site, and site.register committed it. */
    case Registered = 'registered';

    /** The site is registered with the configured locales; nothing was written. */
    case Unchanged = 'unchanged';

    /** The site is registered with other locales than the configured; nothing was written. */
    case Drifted = 'drifted';

    /** The database lacked the site, and the pipeline rejected site.register; nothing was written. */
    case Rejected = 'rejected';

    /**
     * Whether the site is registered with the configured locales after the sync.
     */
    public function synced(): bool
    {
        return $this === self::Registered || $this === self::Unchanged;
    }
}
