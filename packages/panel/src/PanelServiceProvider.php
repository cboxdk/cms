<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Views\PanelRootView;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the panel module, the PHP side of the control panel (PRD 13.4), in a Laravel
 * application. Loaded through package discovery.
 *
 * Binds the panel's build, read from its Vite manifest in buildDirectory() when a panel page or
 * file is first asked for, so a process without the build boots and runs everything else; loads
 * the panel's views under the namespace VIEWS and gives its root view what it needs
 * (PanelRootView). Declares the module's classes as a scan root for cms:build (PRD 13.2). An
 * application mounts the panel with PanelRoutes.
 */
#[Internal]
final class PanelServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    public const string VIEWS = 'cms-panel';

    #[Override]
    public function register(): void
    {
        $this->app->singleton(PanelBuild::class, static fn (): PanelBuild => ViteManifest::read(self::buildDirectory()));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::VIEWS);
        $this->callAfterResolving(Factory::class, static function (Factory $views): void {
            $views->composer(PanelRootView::VIEW, PanelRootView::class);
        });
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
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
