<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\ServedBundle;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * The addresses of the files of the addons' bundles below the panel's addon asset route (PRD
 * 13.4), `<prefix>/addons/<namespace>/<bundle hash>/<path>`, and what a page that loads addons
 * gets from them: its import map with every bundle's entry, scope and integrity, or a dev server's
 * entry for an addon CBOX_CMS_PANEL_DEV_ADDONS names, and the stylesheets of every bundle.
 */
#[Internal]
final readonly class AddonAssetUrls
{
    public function __construct(private UrlGenerator $urls) {}

    /**
     * The address of a file of a bundle.
     */
    public function url(ServedBundle $bundle, BundleFile $file): string
    {
        return $this->urls->route(PanelRoute::AddonAsset->value, ['addon' => $bundle->addon->value, 'hash' => $bundle->hash, 'path' => $file->path->value], false);
    }

    /**
     * The directory every file of a bundle lies below, ending in `/`: the scope the import map
     * gives the addon.
     */
    public function prefix(ServedBundle $bundle): string
    {
        $url = $this->urls->route(PanelRoute::AddonAsset->value, ['addon' => $bundle->addon->value, 'hash' => $bundle->hash, 'path' => 'x'], false);

        return substr($url, 0, strlen($url) - 1);
    }

    /**
     * The page's import map with every bundle, or a dev server for an addon that has one.
     */
    public function importMap(ImportMap $map, ServedBundles $bundles, DevAddons $devAddons): ImportMap
    {
        foreach ($bundles->bundles as $bundle) {
            $server = $devAddons->of($bundle->addon);

            if ($server instanceof DevServer) {
                $map = $map->withDevServer($server);

                continue;
            }

            $integrity = [];

            foreach ($bundle->of(BundleFileKind::Script) as $script) {
                $integrity[$this->url($bundle, $script)] = $script->integrity->value;
            }

            $map = $map->withAddon($bundle->addon, $this->prefix($bundle), $this->url($bundle, $bundle->file($bundle->entry->value) ?? $bundle->of(BundleFileKind::Script)[0]), $integrity);
        }

        foreach ($devAddons->servers as $server) {
            if (! $bundles->of($server->addon) instanceof ServedBundle) {
                $map = $map->withDevServer($server);
            }
        }

        return $map;
    }

    /**
     * The addresses of the stylesheets of every bundle, in namespace and path order; an addon on
     * a dev server loads its own from the server.
     *
     * @return list<string>
     */
    public function styles(ServedBundles $bundles, DevAddons $devAddons): array
    {
        $styles = [];

        foreach ($bundles->bundles as $bundle) {
            if ($devAddons->of($bundle->addon) instanceof DevServer) {
                continue;
            }

            foreach ($bundle->of(BundleFileKind::Style) as $style) {
                $styles[] = $this->url($bundle, $style);
            }
        }

        return $styles;
    }
}
