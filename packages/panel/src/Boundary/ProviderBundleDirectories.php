<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Illuminate\Contracts\Foundation\Application;

/**
 * The bundle directory of each addon the application's service providers declare through
 * DeclaresAddon, from the panel member of their manifests (PRD 13.4), deferred providers
 * included. A manifest that cannot be built names no directory here; cms:build reports it, and
 * the compiled registry, which decides what is served, has no bundle for it.
 */
#[Internal]
final readonly class ProviderBundleDirectories
{
    private function __construct() {}

    public static function of(Application $app): BundleDirectories
    {
        $app->loadDeferredProviders();
        $directories = [];

        foreach ($app->getProviders(DeclaresAddon::class) as $provider) {
            if (! $provider instanceof DeclaresAddon) {
                continue;
            }

            try {
                $manifest = $provider->addonManifest();
            } catch (ReservedAddonNamespace|InvalidAddonManifest|InvalidPanelPoint) {
                continue;
            }

            $bundle = $manifest->panel?->bundle;

            if ($bundle !== null) {
                $directories[$manifest->namespace->value] ??= $bundle;
            }
        }

        ksort($directories, SORT_STRING);

        return new BundleDirectories($directories);
    }
}
