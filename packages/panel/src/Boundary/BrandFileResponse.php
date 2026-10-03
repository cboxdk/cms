<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandFile;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Illuminate\Http\Response;

/**
 * Answers a request for a file of the installation's brand (PRD 13.4): a logo, the login page's
 * image or the favicon, by the name with its hash that the pages link to, from the bytes the panel
 * read from the application. Only a file the brand holds is served, any other name is 404. It is
 * cached for a year, because a changed file has a new name, nosniff keeps the browser to its
 * content type, and its own Content-Security-Policy sandboxes it and lets it load nothing, so an
 * SVG opened on its own runs no script on the panel's origin.
 */
#[Internal]
final readonly class BrandFileResponse
{
    /** The policy of a brand file opened on its own. */
    public const string POLICY = "default-src 'none'; sandbox";

    private function __construct() {}

    public static function of(Branding $branding, string $name): Response
    {
        $file = $branding->file($name);

        if (! $file instanceof BrandFile) {
            return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return new Response($file->contents, 200, [
            'Content-Type' => $file->type->contentType(),
            'Cache-Control' => PanelAssetResponse::CACHE_CONTROL,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::POLICY,
        ]);
    }
}
