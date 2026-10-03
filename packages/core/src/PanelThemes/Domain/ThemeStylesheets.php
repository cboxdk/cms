<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;

/**
 * Where cms:build puts the stylesheet of the composed theme, the cascade layer cms.theme, and where
 * the panel reads it to serve it from its own origin (PRD 13.4).
 */
#[Internal]
interface ThemeStylesheets
{
    /**
     * Replaces the stylesheet, or removes it for the empty string, when no theme is selected.
     *
     * @throws RegistryCacheUnwritable
     */
    public function write(string $css): void;

    /**
     * The stylesheet cms:build wrote last, or null when it wrote none or no theme is selected.
     */
    public function read(): ?string;
}
