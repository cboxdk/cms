<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Illuminate\Http\Response;

/**
 * Answers a request for the theme's stylesheet (PRD 13.4): the text cms:build wrote, as text/css,
 * when the address carries its version, cached for a year and never revalidated, because a new
 * build gives a new address; any other version, or none selected, is 404.
 */
#[Internal]
final readonly class PanelThemeResponse
{
    private function __construct() {}

    public static function of(PanelTheme $theme, string $version): Response
    {
        if ($theme->css === null || $theme->version !== $version) {
            return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return new Response($theme->css, 200, [
            'Content-Type' => PanelAssetResponse::CONTENT_TYPES['css'],
            'Cache-Control' => PanelAssetResponse::CACHE_CONTROL,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
