<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Panel\Domain\Dto\ServedBundle;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

/**
 * Answers a request for a file of an addon's panel bundle (PRD 13.4), below
 * `<prefix>/addons/<namespace>/<bundle hash>/<path>`. Only a file the compiled registry lists for
 * the addon, at the hash of the bundle as cms:build compiled it, is served; any other address is
 * 404, so no request reaches another file. Before the file is sent its bytes are hashed, and a
 * file whose SHA-384 is not the one cms:build compiled, or that is gone, is refused with the
 * problem details of panel_asset_hash_mismatch (CODE): the installation's bundle changed after
 * cms:build, which cms:doctor's panel.addons reports too, and the browser's import map would
 * refuse the file as well. A served file is cached for a year and never revalidated, because its
 * address carries the bundle's hash, and nosniff keeps a browser to the content type.
 *
 * It reads a local file and never a URL: a ServedBundle's directory is an absolute path that names
 * no stream wrapper, a BundlePath never leaves it, and the path is checked again before any file
 * function sees it. The Arch suite allows SplFileObject here because of that (Egress).
 */
#[Internal]
final readonly class AddonAssetResponse
{
    public const string CODE = 'panel_asset_hash_mismatch';

    public const string CACHE_CONTROL = PanelAssetResponse::CACHE_CONTROL;

    private function __construct() {}

    public static function of(ServedBundles $bundles, string $addon, string $hash, string $path): Response
    {
        try {
            $bundle = $bundles->of(new AddonNamespace($addon));
        } catch (InvalidArgumentException) {
            return self::notFound();
        }

        $file = $bundle?->file($path);

        if (! $bundle instanceof ServedBundle || ! $file instanceof BundleFile || ! hash_equals($bundle->hash, $hash)) {
            return self::notFound();
        }

        $bytes = self::contents($bundle->pathOf($file));

        if ($bytes === null || ! BundleIntegrity::of($bytes)->equals($file->integrity)) {
            $problem = Problem::of(ErrorCode::from(self::CODE), sprintf(
                'The file %s of the panel bundle of addon %s is %s. Run cms:build after a change of an addon, and never edit built files; cms:doctor\'s panel.addons lists what changed.',
                $file->path->value,
                $bundle->addon->value,
                $bytes === null ? 'missing or unreadable' : 'not the file cms:build compiled: its bytes changed',
            ));

            return new Response(new ProblemCodecV1()->encode($problem, ClassificationAccess::Public), $problem->status, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return new Response($bytes, 200, [
            'Content-Type' => PanelAssetResponse::CONTENT_TYPES[strtolower(pathinfo($file->path->value, PATHINFO_EXTENSION))] ?? PanelAssetResponse::BYTES,
            'Cache-Control' => self::CACHE_CONTROL,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function notFound(): Response
    {
        return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The bytes of the file, or null when it is missing or cannot be read.
     */
    private static function contents(string $path): ?string
    {
        if (LocalPath::namesStreamWrapper($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 || $size === false ? '' : $file->fread($size);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }
}
