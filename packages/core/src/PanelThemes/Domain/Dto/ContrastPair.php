<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\ContrastKind;

/**
 * A pair of colour tokens the kit draws one on the other, which must keep the contrast of its kind
 * in both modes, in the catalogue and after the selected themes are composed.
 */
#[Experimental]
final readonly class ContrastPair
{
    public function __construct(
        public string $foreground,
        public string $background,
        public ContrastKind $kind,
    ) {}
}
