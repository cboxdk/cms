<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * cms:panel:theme:check's request: the theme file to check on its own over the catalogue.
 */
#[Experimental]
final readonly class ThemeCheckRequest
{
    public function __construct(public string $file) {}
}
