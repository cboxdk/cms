<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;

/**
 * The themes the installation selects for its panel, in the order they compose, later over earlier
 * (cbox-cms.panel.themes), and the file of its own theme, `app`, when it has one
 * (cbox-cms.panel.app_theme). No theme applies that the list does not name.
 */
#[Experimental]
final readonly class ThemeSelection
{
    /**
     * @param  list<ThemeName>  $themes
     */
    public function __construct(
        public array $themes = [],
        public ?string $appTheme = null,
    ) {}
}
