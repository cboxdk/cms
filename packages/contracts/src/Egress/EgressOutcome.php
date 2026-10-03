<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How an outbound request ended, the value of the attribute cms.egress.outcome on the gateway's
 * counters.
 *
 * - Ok: the destination answered with a 2xx status.
 * - Status: it answered with another status that is not a redirect; the caller gets the response.
 * - Redirect: it answered with a 3xx status, which the gateway never follows (egress_redirect_refused).
 * - Blocked: the SSRF guard refused the destination before connecting (egress_blocked).
 * - Unavailable: no answer within the timeouts, or the connection failed (egress_unavailable).
 * - GuardDisabled: the guard's policy does not enforce or does not pin DNS, so the gateway sent
 *   nothing (egress_guard_disabled).
 */
#[Experimental]
enum EgressOutcome: string
{
    case Ok = 'ok';
    case Status = 'status';
    case Redirect = 'redirect';
    case Blocked = 'blocked';
    case Unavailable = 'unavailable';
    case GuardDisabled = 'guard_disabled';

    /** Whether the gateway counts it under cms.egress.failures: every outcome but Ok. */
    public function failed(): bool
    {
        return $this !== self::Ok;
    }
}
