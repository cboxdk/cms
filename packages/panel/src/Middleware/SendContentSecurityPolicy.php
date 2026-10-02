<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every panel page a strict Content-Security-Policy with a nonce of its own (GUARDRAILS 6).
 * It makes a new nonce for the request, puts it on the request under NONCE, where the panel's root
 * view takes it for its script, stylesheets and module preloads (PanelRootView), and sends the
 * policy of ContentSecurityPolicy with that nonce on the response, replacing any policy set before.
 */
#[Internal]
final readonly class SendContentSecurityPolicy
{
    /** The request attribute that holds the request's CspNonce. */
    public const string NONCE = 'cbox-cms.panel.csp-nonce';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = CspNonce::random();
        $request->attributes->set(self::NONCE, $nonce);

        $response = $next($request);
        $response->headers->set(ContentSecurityPolicy::HEADER, ContentSecurityPolicy::header($nonce));

        return $response;
    }

    /**
     * The nonce of the request.
     *
     * @throws LogicException when the request did not pass this middleware
     */
    public static function nonceOf(Request $request): CspNonce
    {
        $nonce = $request->attributes->get(self::NONCE);

        return $nonce instanceof CspNonce ? $nonce : throw new LogicException('The panel page was rendered without its Content-Security-Policy: register its routes with PanelRoutes::register().');
    }
}
