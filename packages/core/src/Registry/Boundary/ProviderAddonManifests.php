<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresCoreContributions;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;

/**
 * The addon manifests the application's service providers declare through DeclaresAddon
 * (PRD 13.1, 13.2). Deferred providers are registered first, so a deferred provider is not missed.
 *
 * A manifest that cannot be built is a problem of the build, not an exception: a reserved
 * namespace is registry_reserved_namespace and anything else registry_invalid_manifest, each
 * naming the provider. So is a manifest whose documentation directory, or schema directory when it
 * has one, is not a readable directory, and one whose field type contributor is not a class that
 * implements FieldTypeContributor. A panel contribution or scope that refuses its values counts as
 * a manifest that cannot be built.
 *
 * The panel bundle of a manifest that names one is read here, through PanelBundles, so the
 * compiler checks it without touching the disk (PRD 13.4).
 *
 * The core's own panel contributions come from the providers of cboxdk/cms's modules that
 * implement DeclaresCoreContributions, in the namespace cms (PRD 13.4). A provider outside the
 * namespace Cbox\Cms that implements it, an anonymous class among them, or whose contributions
 * cannot be built, is a problem of the build, registry_invalid_manifest, naming the provider.
 */
#[Internal]
final readonly class ProviderAddonManifests
{
    /** The namespace of the modules of cboxdk/cms, the only providers that declare the core's contributions. */
    public const string KERNEL_NAMESPACE = 'Cbox\\Cms\\';

    public static function of(Application $app): DeclaredAddons
    {
        $app->loadDeferredProviders();

        $providers = [];

        foreach ([...$app->getProviders(DeclaresAddon::class), ...$app->getProviders(DeclaresCoreContributions::class)] as $provider) {
            if (is_object($provider) && ! in_array($provider, $providers, true)) {
                $providers[] = $provider;
            }
        }

        return self::from($providers);
    }

    /**
     * @param  list<object>  $providers  the registered providers; those that implement neither DeclaresAddon nor DeclaresCoreContributions are passed over
     */
    public static function from(array $providers): DeclaredAddons
    {
        $manifests = [];
        $problems = [];
        $bundles = [];
        $core = [];

        foreach ($providers as $provider) {
            if ($provider instanceof DeclaresCoreContributions) {
                $core = [...$core, ...self::coreContributions($provider, $problems)];
            }

            if (! $provider instanceof DeclaresAddon) {
                continue;
            }

            try {
                $manifest = $provider->addonManifest();
            } catch (ReservedAddonNamespace $reserved) {
                $problems[] = new BuildProblem(BuildErrorCode::ReservedNamespace, sprintf('The service provider %s declares an addon manifest with a reserved namespace. %s', $provider::class, $reserved->getMessage()));

                continue;
            } catch (InvalidAddonManifest|InvalidPanelPoint $invalid) {
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

            $contributor = $manifest->schema->fieldTypeContributor;

            if ($contributor !== null && ! is_subclass_of($contributor, FieldTypeContributor::class)) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf(
                    'The manifest of addon "%s" (%s) names the field type contributor %s, which is not a class that implements %s. Name the class that returns the addon\'s field types.',
                    $manifest->namespace->value,
                    $manifest->package,
                    $contributor,
                    FieldTypeContributor::class,
                ));

                continue;
            }

            $manifests[] = $manifest;
            $bundle = $manifest->panel?->bundle;

            if ($bundle !== null) {
                $bundles[$manifest->package] ??= PanelBundles::read($bundle);
            }
        }

        return new DeclaredAddons($manifests, $problems, $bundles, $core);
    }

    /**
     * The core's contributions a module's provider declares, or none, with a problem, for a
     * provider outside cboxdk/cms or contributions that cannot be built.
     *
     * @param  list<BuildProblem>  $problems
     * @return list<PanelContribution>
     */
    private static function coreContributions(DeclaresCoreContributions $provider, array &$problems): array
    {
        if (new ReflectionClass($provider)->isAnonymous() || ! str_starts_with($provider::class, self::KERNEL_NAMESPACE)) {
            $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf(
                'The service provider %s declares contributions of the core, in the namespace cms, and is not a module of cboxdk/cms. An addon declares its panel contributions in its manifest, PanelContributions, in its own namespace.',
                $provider::class,
            ));

            return [];
        }

        try {
            return $provider->coreContributions();
        } catch (InvalidAddonManifest|InvalidPanelPoint $invalid) {
            $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf('The service provider %s declares contributions of the core that cannot be built. %s', $provider::class, $invalid->getMessage()));

            return [];
        }
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
