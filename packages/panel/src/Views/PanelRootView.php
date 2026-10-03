<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Views;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\HandlePanelRequests;
use Cbox\Cms\Panel\Boundary\ImportMapJson;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\PanelRoutes;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Gives the panel's root view, `cms-panel::app`, what it needs besides Inertia's page: the
 * request's CSP nonce (SendContentSecurityPolicy), the application's locale for `<html lang>`,
 * from which the panel picks its translations, and the addresses of the build's entry script, its
 * stylesheets and its module preloads, below the panel's asset route. The view puts the nonce on
 * each of them and on the `csp-nonce` meta element, where Inertia and Vite find it for the style
 * and preload elements they add later. It also gives the page's import map (ImportMap), which the
 * view writes, with the nonce, before any module is loaded, as a browser requires.
 */
#[Internal]
final readonly class PanelRootView
{
    public const string VIEW = HandlePanelRequests::ROOT_VIEW;

    public function __construct(
        private Request $request,
        private PanelBuild $build,
        private UrlGenerator $urls,
        private Application $app,
        private ImportMap $importMap,
    ) {}

    public function compose(View $view): void
    {
        $view->with([
            'cspNonce' => SendContentSecurityPolicy::nonceOf($this->request)->value,
            'panelLocale' => str_replace('_', '-', $this->app->getLocale()),
            'panelImportMap' => ImportMapJson::encode($this->importMap),
            'panelScript' => $this->url($this->build->entry),
            'panelStyles' => array_map($this->url(...), $this->build->styles),
            'panelPreloads' => array_map($this->url(...), $this->build->preloads),
        ]);
    }

    /**
     * The import map of a page of the panel's build, which a test or the addon runtime replaces
     * with one that has the addons' scopes.
     */
    public static function importMap(PanelBuild $build, UrlGenerator $urls): ImportMap
    {
        return ImportMap::of($build, static fn (string $file): string => $urls->route(PanelRoutes::ASSET, ['path' => $file], false));
    }

    private function url(string $file): string
    {
        return $this->urls->route(PanelRoutes::ASSET, ['path' => $file], false);
    }
}
