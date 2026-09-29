<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A purge of one dependency key (PRD 8.12 point 1): the key, the commit position of the changeset
 * that changed its content, and the instant the purge fence ends.
 *
 * Until $fenceUntil the store refuses every fragment that depends on the key and was built at or
 * below $position, because such a read may not have seen the change: it may have run on a replica
 * that lags, or started before the commit. Size the fence to outlast the slowest build and the
 * replica lag; after it, a build that old no longer exists. $fenceUntil is kept in UTC.
 */
#[Experimental]
final readonly class FragmentPurge
{
    public DateTimeImmutable $fenceUntil;

    public function __construct(
        public DependencyKey $key,
        public CommitPosition $position,
        DateTimeImmutable $fenceUntil,
    ) {
        $this->fenceUntil = $fenceUntil->setTimezone(new DateTimeZone('UTC'));
    }
}
