<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A brand image in a light and a dark version, with the text that stands for it, which a screen
 * reader announces.
 */
#[Internal]
final readonly class BrandImage
{
    public function __construct(
        public BrandFile $light,
        public BrandFile $dark,
        public string $alt,
    ) {}
}
