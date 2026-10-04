<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\PanelRoutes;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every panel page a strict Content-Security-Policy (GUARDRAILS 6). It makes a new nonce for
 * the request, for the page's styles, and puts it on the request under NONCE, where the panel's
 * root view takes it (PanelRootView). The root view records the text of each inline script it
 * writes, the page's import map, with allowInlineScript(), and once the response is made, the
 * middleware sends the policy of ContentSecurityPolicy with the nonce, the hashes of those
 * scripts, the panel's report route when it is registered and, on a page that loads addons, the
 * dev servers of CBOX_CMS_PANEL_DEV_ADDONS, replacing any policy set before. A response without
 * inline scripts, such as Inertia's JSON, gets a policy that allows none.
 */
#[Internal]
final readonly class SendContentSecurityPolicy
{
    /** The request attribute that holds the request's CspNonce. */
    public const string NONCE = 'cbox-cms.panel.csp-nonce';

    /** The request attribute that holds the SHA-256, in base64, of each inline script of the page. */
    public const string INLINE_SCRIPTS = 'cbox-cms.panel.csp-inline-scripts';

    public function __construct(
        private Router $router,
        private UrlGenerator $urls,
        private DevAddons $devAddons,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = CspNonce::random();
        $request->attributes->set(self::NONCE, $nonce);
        $request->attributes->set(self::INLINE_SCRIPTS, []);

        $response = $next($request);
        $reportPath = $this->router->has(PanelRoute::CspReport->value) ? $this->urls->route(PanelRoute::CspReport->value, [], false) : null;
        $policy = new PagePolicy(
            $nonce,
            self::inlineScriptsOf($request),
            $reportPath,
            PanelRoutes::allowsAddons($request) ? $this->devAddons->servers : [],
        );

        $response->headers->set(ContentSecurityPolicy::HEADER, ContentSecurityPolicy::header($policy));

        if ($reportPath !== null) {
            $response->headers->set(ContentSecurityPolicy::REPORTING_ENDPOINTS, ContentSecurityPolicy::reportingEndpoints($reportPath));
        }

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

    /**
     * Lets the inline script with the text run on the page: the policy of the response names its
     * hash.
     *
     * @throws LogicException when the request did not pass this middleware
     */
    public static function allowInlineScript(Request $request, string $script): void
    {
        self::nonceOf($request);
        $request->attributes->set(self::INLINE_SCRIPTS, [...self::inlineScriptsOf($request), PagePolicy::hashOf($script)]);
    }

    /**
     * The SHA-256, in base64, of each inline script allowed on the page so far.
     *
     * @return list<string>
     */
    public static function inlineScriptsOf(Request $request): array
    {
        $hashes = $request->attributes->get(self::INLINE_SCRIPTS);
        $list = [];

        foreach (is_array($hashes) ? $hashes : [] as $hash) {
            if (is_string($hash)) {
                $list[] = $hash;
            }
        }

        return $list;
    }
}
