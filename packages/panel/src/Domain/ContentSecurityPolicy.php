<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The Content-Security-Policy of every panel page (GUARDRAILS 6: the control panel has a strict
 * policy with nonces). Scripts run only when the response names them with its nonce, and what such
 * a script imports ('strict-dynamic'), so an injected inline script, event handler attribute or
 * script from another origin never runs; there is no 'unsafe-inline' and no 'unsafe-eval'.
 * Styles come from the panel's own origin or carry the nonce, so an injected style element does
 * not apply. Everything else is the panel's own origin, images may also be data: URLs, plugins and
 * <base> are refused, forms post only to the panel's origin and no other site may frame it.
 */
#[Internal]
final readonly class ContentSecurityPolicy
{
    public const string HEADER = 'Content-Security-Policy';

    private function __construct() {}

    /**
     * The header's value for a response with the nonce.
     */
    public static function header(CspNonce $nonce): string
    {
        $source = "'nonce-{$nonce->value}'";

        return implode('; ', [
            "default-src 'self'",
            "script-src {$source} 'strict-dynamic'",
            "style-src 'self' {$source}",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
