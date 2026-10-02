<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;

/**
 * The one way out of the process for HTTP (GUARDRAILS 3, PRD 7.14). Every outbound request of the
 * kernel, its modules and its addons goes through it, and the Arch suite fails on any other.
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
    /**
     * @throws EgressFailed when the guard refuses the URL, the destination redirects, it does not answer in time, or the guard is off
     */
    public function get(EgressRequest $request): EgressResponse;
}
