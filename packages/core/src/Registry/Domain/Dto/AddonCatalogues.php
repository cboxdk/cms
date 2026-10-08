<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;

/**
 * An addon's panel catalogues as cms:build read them from the directory its PanelContributions
 * name (PRD 13.4): one PanelCatalogue per locale the panel ships whose file could be read, in the
 * order of PanelLocale, and what was wrong with the files on disk, each described. The compiler
 * reports every one as registry_panel_catalogue_invalid and the locales that are missing as
 * registry_panel_translations_incomplete.
 */
#[Experimental]
final readonly class AddonCatalogues
{
    /**
     * @param  list<PanelCatalogue>  $catalogues  one per locale that was read, in PanelLocale's order
     * @param  list<string>  $problems
     */
    public function __construct(
        public array $catalogues = [],
        public array $problems = [],
    ) {}

    /**
     * The catalogue of the locale, or null when its file could not be read.
     */
    public function of(PanelLocale $locale): ?PanelCatalogue
    {
        foreach ($this->catalogues as $catalogue) {
            if ($catalogue->locale === $locale) {
                return $catalogue;
            }
        }

        return null;
    }
}
