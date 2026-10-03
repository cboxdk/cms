<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A brand image in the props of the panel's pages: the addresses of its light and dark version and
 * its alternative text (PanelBrand).
 */
#[Internal]
final readonly class PanelBrandLogo
{
    public function __construct(
        public string $alt,
        public string $dark,
        public string $light,
    ) {}
}
