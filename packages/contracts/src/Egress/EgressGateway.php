<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The one way out of the process for HTTP (GUARDRAILS 3, PRD 7.14). Every outbound request of the
 * kernel, its modules and its addons goes through it, and the Arch suite fails on any other. It is a
 * contract (GUARDRAILS 2.3): cbox-cms.contracts binds it to the core's SsrfEgressGateway unless an
 * application names another class, the testkit's FakeEgressGateway stands in for it in tests, and
 * every implementation runs the shared suite EgressGatewayContract.
 *
 * get() sends a GET over https to the request's URL with its headers, after the SSRF guard of
 * cboxdk/laravel-ssrf has checked the URL: it refuses private, reserved and cloud metadata
 * addresses, blocked hosts, credentials in the URL and every scheme but https, and pins the
 * connection to the addresses it checked, so DNS cannot point it elsewhere after the check. It
 * never follows a redirect. It waits at most the connect and total timeouts of cbox-cms.egress.
 *
 * It returns the response for any status but a redirect, and throws EgressFailed when it sent
 * nothing or got no answer it hands on. Every request adds 1 to the counter cms.egress.requests,
 * and every one that does not end with a 2xx status to cms.egress.failures, each under the host
 * class and the outcome (EgressOutcome), never the URL.
 */
#[Experimental]
interface EgressGateway
{
    /** The counter of every outbound request. */
    public const string REQUESTS = 'cms.egress.requests';

    /** The counter of every outbound request, and every mail, that did not end with Ok. */
    public const string FAILURES = 'cms.egress.failures';

    /** The attribute of the counters that holds the host class. */
    public const string HOST_CLASS = 'cms.egress.host_class';

    /** The attribute of the counters that holds the outcome, an EgressOutcome. */
    public const string OUTCOME = 'cms.egress.outcome';

    /**
     * @throws EgressFailed when the guard refuses the URL, the destination redirects, it does not answer in time, or the guard is off
     */
    public function get(EgressRequest $request): EgressResponse;
}
