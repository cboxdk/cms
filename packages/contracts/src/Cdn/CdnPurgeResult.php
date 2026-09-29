<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a CdnDriver did with a purge: the purge as the edge applied it, and how many requests it
 * took. The applied mode is the requested one, or Hard for a Soft purge on a driver that cannot
 * purge softly; a Hard purge is never applied softly.
 */
#[Experimental]
final readonly class CdnPurgeResult
{
    public function __construct(
        public CdnPurge $applied,
        public int $requests,
    ) {
        if ($requests < 1) {
            throw InvalidCdnPurge::requests($requests);
        }
    }
}
