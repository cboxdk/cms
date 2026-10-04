<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;

/**
 * The Content-Security-Policy of every panel page (GUARDRAILS 6: the control panel has a strict
 * policy). Scripts come only from the panel's own origin, and an inline script runs only when the
 * policy names the hash of its text, the page's import map; there is no nonce and no
 * 'strict-dynamic' for scripts, because a browser hands a script's nonce on to what it imports
 * with import(), and only 'self' with hashes keeps a trusted script's import() on the panel's
 * origin (D6, shown in tests/Browser/Panel/PanelCspAddonModulesTest.php). So an injected inline
 * script, event handler attribute or script from another origin never runs; there is no
 * 'unsafe-inline' and no 'unsafe-eval'. Styles come from the panel's own origin or carry the
 * response's nonce, so an injected style element does not apply. Everything else is the panel's
 * own origin, images may also be data: URLs, plugins, frames and <base> are refused, forms post
 * only to the panel's origin and no other site may frame it.
 *
 * A violation is reported to the panel's report route, through the Reporting API (report-to, with
 * the Reporting-Endpoints header REPORTING_ENDPOINTS) and report-uri for a browser without it. A
 * dev server of an addon's panel UI, in the local environment only, is let in for scripts and for
 * connections, its websocket included.
 */
#[Internal]
final readonly class ContentSecurityPolicy
{
    public const string HEADER = 'Content-Security-Policy';

    /** The header that names the endpoint of the Reporting API's group. */
    public const string REPORTING_ENDPOINTS = 'Reporting-Endpoints';

    /** The group the policy reports to. */
    public const string REPORT_GROUP = 'cms-csp';

    private function __construct() {}

    /**
     * The header's value for a response.
     */
    public static function header(PagePolicy $policy): string
    {
        $scripts = ["'self'", ...array_map(static fn (string $hash): string => "'sha256-{$hash}'", $policy->inlineScripts)];
        $connects = ["'self'"];

        foreach ($policy->devServers as $server) {
            $scripts[] = $server->origin;
            $connects[] = $server->origin;
            $connects[] = $server->websocket();
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $scripts),
            "style-src 'self' 'nonce-{$policy->nonce->value}'",
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connects),
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if ($policy->reportPath !== null) {
            $directives[] = "report-uri {$policy->reportPath}";
            $directives[] = 'report-to '.self::REPORT_GROUP;
        }

        return implode('; ', $directives);
    }

    /**
     * The Reporting-Endpoints header's value, which names where the group's reports go.
     */
    public static function reportingEndpoints(string $reportPath): string
    {
        return self::REPORT_GROUP.'="'.$reportPath.'"';
    }
}
