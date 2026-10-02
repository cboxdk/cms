<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Override;

/**
 * The CSRF protection of every state-changing panel request (GUARDRAILS 6, PRD 5.16): Laravel's own,
 * which takes a request that reads, one a browser marks as sent from the same origin, or one that
 * carries the CSRF token of Laravel's session in the field `_token`, the header X-CSRF-TOKEN or the
 * header X-XSRF-TOKEN that Inertia sends from the XSRF-TOKEN cookie, and refuses any other with
 * 419. Unlike the application's copy in the web middleware group, it checks while unit tests run
 * too, so the panel's own tests show it refusing, and it excludes no path, whatever the application
 * excludes from its own with PreventRequestForgery::except().
 */
#[Internal]
final class VerifyPanelCsrfToken extends PreventRequestForgery
{
    #[Override]
    protected function runningUnitTests()
    {
        return false;
    }

    /**
     * @param  Request  $request
     */
    #[Override]
    protected function inExceptArray($request)
    {
        return false;
    }
}
