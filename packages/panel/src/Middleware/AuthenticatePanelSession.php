<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates every panel request but the login page's from the session cookie (PRD 5.16): the
 * request goes on only with a session that verifies, renewed by the verification, and with
 * Laravel's session bound to it (PanelSessions::authenticate()). Any other request is redirected to
 * the login page with the reason.
 */
#[Internal]
final readonly class AuthenticatePanelSession
{
    public function __construct(private PanelSessions $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->sessions->authenticate($request) ?? $next($request);
    }
}
