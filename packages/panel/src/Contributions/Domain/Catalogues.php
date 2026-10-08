<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonPanel;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\PanelCompiler;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActivePoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonTexts;

/**
 * The texts a panel page carries for the addons whose contributions are active on it (section 2.6
 * of the panel extension architecture): per addon, the catalogue of the active locale alone, as
 * cms:build compiled it from the addon's resources/panel/lang/<locale>.json, so no other locale is
 * ever sent and nothing the viewer does not get is named.
 *
 * The core's own contributions, in the namespace cms, are left out: the panel's own catalogue is
 * part of its build and the host reads it in the browser. An addon that ships no catalogue gets an
 * empty one, and every key its contributions name shows as the key, as addonTexts() does.
 */
#[Experimental]
final readonly class Catalogues
{
    private function __construct() {}

    /**
     * The texts of each addon with an active contribution on the page, sorted by namespace.
     *
     * @param  list<ActivePoint>  $points
     * @return list<AddonTexts>
     */
    public static function of(CompiledRegistry $registry, array $points, PanelLocale $locale): array
    {
        $addons = [];

        foreach ($points as $point) {
            foreach ($point->fills as $fill) {
                $addon = $fill->fill->addon();

                if ($addon->value !== PanelCompiler::CORE_NAMESPACE) {
                    $addons[$addon->value] = $addon;
                }
            }
        }

        ksort($addons, SORT_STRING);

        return array_values(array_map(
            static function (AddonNamespace $addon) use ($registry, $locale): AddonTexts {
                $panel = $registry->addon($addon)?->panel;

                return new AddonTexts($addon, $panel instanceof AddonPanel ? $panel->texts($locale) : []);
            },
            $addons,
        ));
    }
}
