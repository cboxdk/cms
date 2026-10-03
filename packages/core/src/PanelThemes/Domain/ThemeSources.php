<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;

/**
 * Where the panel's themes are read from (PRD 13.4): the kit's token catalogue, and the theme
 * files of the application and of the addons, each checked against the catalogue as theme.v1.json
 * describes it.
 */
#[Internal]
interface ThemeSources
{
    /**
     * @throws TokenCatalogueUnavailable
     */
    public function catalogue(): TokenCatalogue;

    /**
     * The theme in the file, checked against the catalogue.
     *
     * @throws InvalidTheme
     */
    public function theme(ThemeName $name, string $file, TokenCatalogue $catalogue): Theme;
}
