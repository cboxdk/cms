<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Views\PanelRootView;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the panel module, the PHP side of the control panel (PRD 13.4), in a Laravel
 * application. Loaded through package discovery.
 *
 * Binds the panel's build, read from its Vite manifest in buildDirectory() when a panel page or
 * file is first asked for, so a process without the build boots and runs everything else; loads
 * the panel's views under the namespace VIEWS and gives its root view what it needs
 * (PanelRootView). Leaves the session cookie out of Laravel's cookie encryption, because its value
 * is a random id with a checksum that only the session store can use (docs/security/sessions.md),
 * and clears Laravel's session cookie once the kernel has handled a logout
 * (PanelSessions::clearCookies()). Declares the module's classes as a scan root for cms:build (PRD
 * 13.2). An application mounts the panel with PanelRoutes.
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
        $this->app->bind(ImportMap::class, static fn (Application $app): ImportMap => PanelRootView::importMap($app->make(PanelBuild::class), $app->make(UrlGenerator::class)));
    }

    public function boot(): void
    {
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
     * The absolute directory of the panel's build, packages/panel/dist, which `composer
     * panel:build` writes from js/panel and git ignores.
     */
    public static function buildDirectory(): string
    {
        $module = realpath(__DIR__.'/..');

        return (is_string($module) ? $module : __DIR__.'/..').'/dist';
    }
}
