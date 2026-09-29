<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Illuminate\Contracts\Foundation\Application;

/**
 * The addon manifests the application's service providers declare through DeclaresAddon
 * (PRD 13.1, 13.2). Deferred providers are registered first, so a deferred provider is not missed.
 *
 * A manifest that cannot be built is a problem of the build, not an exception: a reserved
 * namespace is registry_reserved_namespace and anything else registry_invalid_manifest, each
 * naming the provider. So is a manifest whose documentation directory, or schema directory when it
 * has one, is not a readable directory.
 */
#[Internal]
final readonly class ProviderAddonManifests
{
    public static function of(Application $app): DeclaredAddons
    {
        $app->loadDeferredProviders();

        return self::from(array_values(array_filter($app->getProviders(DeclaresAddon::class), is_object(...))));
    }

    /**
     * @param  list<object>  $providers  the registered providers; those that do not implement DeclaresAddon are passed over
     */
    public static function from(array $providers): DeclaredAddons
    {
        $manifests = [];
        $problems = [];

        foreach ($providers as $provider) {
            if (! $provider instanceof DeclaresAddon) {
                continue;
            }

            try {
                $manifest = $provider->addonManifest();
            } catch (ReservedAddonNamespace $reserved) {
                $problems[] = new BuildProblem(BuildErrorCode::ReservedNamespace, sprintf('The service provider %s declares an addon manifest with a reserved namespace. %s', $provider::class, $reserved->getMessage()));

                continue;
            } catch (InvalidAddonManifest $invalid) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf('The service provider %s declares an addon manifest that cannot be built. %s', $provider::class, $invalid->getMessage()));

                continue;
            }

            $unreadable = self::unreadableDirectories($manifest);

            if ($unreadable !== []) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf(
                    'The manifest of addon "%s" (%s) names %s, which %s not a readable directory. Every addon has its documentation (PRD 13.1): point the manifest at directories that exist, built from __DIR__.',
                    $manifest->namespace->value,
                    $manifest->package,
                    implode(' and ', $unreadable),
                    count($unreadable) === 1 ? 'is' : 'are',
                ));

                continue;
            }

            $manifests[] = $manifest;
        }

        return new DeclaredAddons($manifests, $problems);
    }

    /**
     * @return list<string> each directory of the manifest that is not a readable directory, described
     */
    private static function unreadableDirectories(AddonManifest $manifest): array
    {
        $unreadable = [];

        foreach (['documentation' => $manifest->docs, 'schema' => $manifest->schema->directory] as $what => $directory) {
            if ($directory !== null && (! is_dir($directory) || ! is_readable($directory))) {
                $unreadable[] = sprintf('the %s directory %s', $what, $directory);
            }
        }

        return $unreadable;
    }
}
