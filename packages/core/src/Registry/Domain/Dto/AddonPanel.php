<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * An addon's panel contributions in the registry, beyond the fills of each point (PRD 13.4): the
 * panel API version it needs, the experimental points it accepts, sorted, its checked bundle, or
 * null when none of its contributions runs code, and its panel catalogues, one per locale the
 * panel ships, which the page sends for the active locale alone (section 2.6 of the panel
 * extension architecture).
 */
#[Experimental]
final readonly class AddonPanel
{
    /**
     * @param  list<PointId>  $acceptsExperimental
     * @param  list<PanelCatalogue>  $catalogues  one per locale, in PanelLocale's order
     */
    public function __construct(
        public PanelApiVersion $sdk,
        public array $acceptsExperimental,
        public ?CompiledBundle $bundle,
        public array $catalogues = [],
    ) {}

    /**
     * The texts of the locale, by key; none when the addon ships no catalogue for it.
     *
     * @return array<string, string>
     */
    public function texts(PanelLocale $locale): array
    {
        foreach ($this->catalogues as $catalogue) {
            if ($catalogue->locale === $locale) {
                return $catalogue->texts;
            }
        }

        return [];
    }
}
