<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Subscribers;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The lane a subscription runs in (PRD 7.6). Each lane has its own pool of workers and its own lag
 * target, so a slow webhook cannot delay a purge.
 */
#[Experimental]
enum Lane: string
{
    /** Fragment invalidation, purges, removal from the search index, the mirror of actors' state: p95 under 500 ms. */
    case Critical = 'critical';

    /** Search reindexing, feeds, sitemaps, the consistency checker: p95 under 60 s. */
    case Standard = 'standard';

    /** Webhooks, partner deliveries, shipping to a SIEM: p95 under 5 min. */
    case External = 'external';

    /** Revalidation webhooks to headless frontends, with a circuit breaker per endpoint: p95 under 2 s. */
    case Revalidate = 'revalidate';

    /** Analytics, the vector index, a customer's replica: best effort. */
    case Background = 'background';
}
