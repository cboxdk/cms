<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresCoreContributions;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Boundary\Generated\Points\PanelPointCodecs;
use Cbox\Cms\Panel\Boundary\InstalledBundles;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Boundary\ProviderBundleDirectories;
use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Doctor\Boundary\ConfigBrandingProbe;
use Cbox\Cms\Panel\Doctor\Boundary\DiskAddonBundlesProbe;
use Cbox\Cms\Panel\Doctor\Boundary\EnvironmentDevServerProbe;
use Cbox\Cms\Panel\Doctor\Domain\Checks\AddonBundlesCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\BrandingCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\DevServerCheck;
use Cbox\Cms\Panel\Doctor\Domain\Probes\AddonBundlesProbe;
use Cbox\Cms\Panel\Doctor\Domain\Probes\BrandingProbe;
use Cbox\Cms\Panel\Doctor\Domain\Probes\DevServerProbe;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Cbox\Cms\Panel\Domain\PanelDevServerForbidden;
use Cbox\Cms\Panel\Views\PanelRootView;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;

/**
 * Registers the panel module, the PHP side of the control panel (PRD 13.4), in a Laravel
 * application. Loaded through package discovery.
 *
 * Binds the panel's build, read from its Vite manifest in buildDirectory() when a panel page or
 * file is first asked for, so a process without the build boots and runs everything else; the
 * installation's brand from cbox-cms.panel.branding and the stylesheet of the composed theme,
 * each read once per process; the bundles of the installed addons' panel UI (ServedBundles), from
 * the compiled registry and the directories the addons' providers name, read when a page that
 * loads addons or one of their files is first asked for; and the dev servers of
 * CBOX_CMS_PANEL_DEV_ADDONS (DevAddons). Adds panel.branding, panel.addons and panel.dev_server
 * to cms:doctor's checks, after the identity module's. Refuses to boot a process that serves HTTP
 * or runs queued jobs with CBOX_CMS_PANEL_DEV_ADDONS outside the local environment
 * (PanelDevServerForbidden), because the variable widens the panel's content security policy to
 * a dev server; a console process boots, so cms:doctor can report it. Loads
 * the panel's views under the namespace VIEWS and gives its root view what it needs
 * (PanelRootView). Leaves the session cookie out of Laravel's cookie encryption, because its value
 * is a random id with a checksum that only the session store can use (docs/security/sessions.md),
 * and clears Laravel's session cookie once the kernel has handled a logout
 * (PanelSessions::clearCookies()). Declares the module's classes as a scan root for cms:build (PRD
 * 13.2), and the directory of its points' props schemas, which cms:build checks the addons'
 * contributions against (PRD 13.4), and the codecs of the points' props, which the panel hands
 * each active contribution its props with (PointCodecs), and the core's own contributions to the
 * points, in the namespace cms (CoreContributions). An application mounts the panel with
 * PanelRoutes.
 */
#[Internal]
final class PanelServiceProvider extends ServiceProvider implements DeclaresCoreContributions, DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    public const string VIEWS = 'cms-panel';

    /** The container id of the panel's PointSchemaDirectory. */
    public const string POINT_SCHEMAS = 'cbox-cms.panel.point-schemas';

    /**
     * The checks the module adds to cms:doctor, in their order.
     *
     * @var list<class-string<DoctorCheck>>
     */
    public const array DOCTOR_CHECKS = [BrandingCheck::class, AddonBundlesCheck::class, DevServerCheck::class];

    #[Override]
    public function register(): void
    {
        $config = $this->app->make(Repository::class);
        $key = DoctorConfig::CONFIG_KEY.'.'.DoctorConfig::CHECKS;
        $added = $config->get($key) ?? [];

        // After the identity module's checks and in front of the application's. A setting that is
        // not a list stays as it is, so the doctor reports it as doctor.config.
        if (is_array($added)) {
            $identity = [];

            foreach ($added as $check) {
                if (in_array($check, IdentityServiceProvider::DOCTOR_CHECKS, true)) {
                    $identity[] = $check;
                }
            }

            $config->set($key, array_values(array_unique([...$identity, ...self::DOCTOR_CHECKS, ...$added], SORT_REGULAR)));
        }

        $this->app->singleton(PanelBuild::class, static fn (): PanelBuild => ViteManifest::read(self::buildDirectory()));

        // The installation's brand, read once per process from cbox-cms.panel.branding and the
        // files it names. A brand that cannot be used is no brand: the panel shows Cbox CMS, and
        // cms:doctor's panel.branding says why.
        $this->app->singleton(static function (Application $app): Branding {
            try {
                return BrandingConfig::read($app->make(Repository::class), $app->basePath());
            } catch (InvalidBranding) {
                return new Branding;
            }
        });
        $this->app->bind(BrandingProbe::class, static fn (Application $app): BrandingProbe => new ConfigBrandingProbe($app->make(Repository::class), $app->basePath()));

        // The stylesheet of the composed theme, read once per process from what cms:build wrote.
        $this->app->singleton(PanelTheme::class, static fn (Application $app): PanelTheme => new PanelTheme($app->make(ThemeStylesheets::class)->read()));
        $this->app->bind(ImportMap::class, static fn (Application $app): ImportMap => PanelRootView::importMap($app->make(PanelBuild::class), $app->make(UrlGenerator::class)));

        // The addons' panel bundles (PRD 13.4): the compiled registry says which files and hashes,
        // the providers' manifests where they are. Read once per process, when first needed.
        $this->app->singleton(static fn (Application $app): BundleDirectories => ProviderBundleDirectories::of($app));
        $this->app->singleton(static fn (Application $app): ServedBundles => InstalledBundles::read($app->make(RegistryCache::class)->read(), $app->make(BundleDirectories::class)));
        $this->app->singleton(static fn (): DevAddons => DevAddonsEnvironment::read());
        $this->app->bind(AddonBundlesProbe::class, static fn (Application $app): AddonBundlesProbe => new DiskAddonBundlesProbe($app->make(RegistryCache::class), $app->make(BundleDirectories::class)));
        $this->app->bind(DevServerProbe::class, static fn (Application $app): DevServerProbe => new EnvironmentDevServerProbe($app));

        // The props schemas of the panel's points, which cms:build checks contributions against.
        $this->app->bind(self::POINT_SCHEMAS, static fn (): PointSchemaDirectory => new PointSchemaDirectory(self::pointSchemaDirectory()));
        $this->app->tag([self::POINT_SCHEMAS], PointSchemaDirectory::TAG);

        // The codecs of the points' props (PRD 13.4), which the panel hands each contribution its
        // props with: the panel's own, which composer generate:protocol lists, and any a module or
        // addon tags under PointCodecs::TAG.
        $this->app->singleton(static function (Application $app): PointCodecs {
            $codecs = [];

            foreach ($app->tagged(PointCodecs::TAG) as $codec) {
                $codecs[] = $codec instanceof PointCodec ? $codec : throw new LogicException(sprintf('The service %s under the tag %s is not a %s.', get_debug_type($codec), PointCodecs::TAG, PointCodec::class));
            }

            return new PointCodecs(...$codecs);
        });

        foreach (PanelPointCodecs::all() as $codec) {
            $id = PointCodecs::TAG.'.'.$codec->point->toString();
            $this->app->instance($id, $codec);
            $this->app->tag($id, PointCodecs::TAG);
        }
    }

    /**
     * The directory of the props schemas of the panel's points, `<name>.v<version>.json` each.
     */
    public static function pointSchemaDirectory(): string
    {
        return __DIR__.'/../resources/schemas/points';
    }

    /**
     * @throws PanelDevServerForbidden
     */
    public function boot(): void
    {
        $this->refuseDevServerOutsideLocal();
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::VIEWS);
        $this->callAfterResolving(Factory::class, static function (Factory $views): void {
            $views->composer(PanelRootView::VIEW, PanelRootView::class);
        });

        $app = $this->app;

        // Asked only when a request's cookies are encrypted, so a console process never reads the
        // session cookie's setting, which cms:doctor reports on instead.
        $this->callAfterResolving(EncryptCookies::class, static function () use ($app): void {
            EncryptCookies::except($app->make(SessionCookie::class)->name);
        });
        $this->app->make(Dispatcher::class)->listen(RequestHandled::class, static function (RequestHandled $handled) use ($app): void {
            if ($handled->request->attributes->get(PanelSessions::ENDED) === true) {
                $app->make(PanelSessions::class)->clearCookies($handled->request, $handled->response);
            }
        });
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }

    /**
     * A process that serves the panel never runs with a dev server outside the local environment.
     *
     * @throws PanelDevServerForbidden
     */
    private function refuseDevServerOutsideLocal(): void
    {
        if (ProcessWorkload::of($this->app) === Workload::Console || DevAddonsEnvironment::raw() === null) {
            return;
        }

        $environment = $this->app->environment();

        if ($environment !== DevServerCheck::LOCAL) {
            throw PanelDevServerForbidden::outsideLocal(DevAddonsEnvironment::VARIABLE, $environment);
        }

        // Invalid text throws InvalidDevAddons here, so the process does not serve pages with it.
        DevAddonsEnvironment::read();
    }

    #[Override]
    public function coreContributions(): array
    {
        return CoreContributions::all();
    }

    /**
     * The absolute directory of the panel's build, packages/panel/dist, which `composer
     * panel:build` writes from js/panel and git ignores.
     */
    public static function buildDirectory(): string
    {
        $module = realpath(__DIR__.'/..');

        return (is_string($module) ? $module : __DIR__.'/..').'/dist';
    }
}
