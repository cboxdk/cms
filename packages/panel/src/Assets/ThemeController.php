<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Assets;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelThemeResponse;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Illuminate\Http\Response;

/**
 * The stylesheet of the panel's theme; PanelThemeResponse answers. It holds no logic of its own.
 */
#[Internal]
final readonly class ThemeController
{
    public function __construct(private PanelTheme $theme) {}

    public function __invoke(string $version): Response
    {
        return PanelThemeResponse::of($this->theme, $version);
    }
}
