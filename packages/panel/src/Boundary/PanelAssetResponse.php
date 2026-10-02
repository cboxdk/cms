<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Illuminate\Http\Response;
use RuntimeException;
use SplFileObject;

/**
 * Answers a request for a file of the panel's build (PRD 13.4): the panel module hands out its own
 * files, so an application needs no copy of them in its public directory. Only a file the build's manifest names is served; any other path is 404,
 * so no request reaches another file. The file names carry a hash of their content, so a served
 * file is cached for a year and never revalidated, and nosniff keeps a browser to the content type.
 *
 * It reads a local file and never a URL: PanelBuild's directory is an absolute path that names no
 * stream wrapper, and the path is checked again before any file function sees it. The Arch suite
 * allows SplFileObject here because of that (Egress).
 */
#[Internal]
final readonly class PanelAssetResponse
{
    public const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /**
     * The content type of each extension Vite writes; any other file is sent as bytes.
     *
     * @var array<string, string>
     */
    public const array CONTENT_TYPES = [
        'avif' => 'image/avif',
        'css' => 'text/css; charset=utf-8',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public const string BYTES = 'application/octet-stream';

    private function __construct() {}

    public static function of(PanelBuild $build, string $file): Response
    {
        if (! $build->serves($file)) {
            return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        $path = $build->pathOf($file);

        return new Response(self::contents($path), 200, [
            'Content-Type' => self::CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? self::BYTES,
            'Cache-Control' => self::CACHE_CONTROL,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function contents(string $path): string
    {
        if (LocalPath::namesStreamWrapper($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The panel's build names {$path}, which does not exist or cannot be read. Build the panel again with `composer panel:build`.");
        }

        $file = new SplFileObject($path, 'rb');
        $size = $file->getSize();
        $contents = $size === 0 || $size === false ? '' : $file->fread($size);

        return is_string($contents) ? $contents : throw new RuntimeException("The panel's build file {$path} cannot be read.");
    }
}
