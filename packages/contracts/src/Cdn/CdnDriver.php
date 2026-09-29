<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A CDN's purge by surrogate key (PRD 8.12, 9.3; GUARDRAILS 2.3). The edge purges its objects
 * through its own index of surrogate keys, independent of the FragmentStore.
 *
 * - purge() purges every key of the purge, in requests of at most maxKeysPerRequest() keys in the
 *   order given, and returns the purge as applied with the number of requests. A Soft purge on a
 *   driver whose supportsSoftPurge() is false is applied Hard. A purge is idempotent: purging a
 *   key again purges it again. When the CDN does not take a request, purge() throws
 *   CdnUnavailable; the requests before it may have been applied.
 * - supportsSoftPurge() says whether the CDN can mark objects stale instead of removing them.
 * - maxKeysPerRequest() is the most keys the CDN takes in one request, at least 1.
 *
 * Purge credentials live in a worker, scoped to one service or zone, never in the request path
 * (PRD 8.10 point 5), so the purge subscriber runs a driver and a web request never does.
 */
#[Experimental]
interface CdnDriver
{
    /**
     * @throws CdnUnavailable when the CDN does not take a request
     */
    public function purge(CdnPurge $purge): CdnPurgeResult;

    public function supportsSoftPurge(): bool;

    /**
     * @return positive-int
     */
    public function maxKeysPerRequest(): int;
}
