<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\LayoutWidth;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A placement in a blueprint's `layout` (gap G2): the handle of a top-level field of the file and
 * the width it takes, as the file writes it. `width()` is the preset, or null for a word that is
 * not one, which BlueprintRules refuses.
 */
#[Internal]
final readonly class LayoutField
{
    public function __construct(
        public string $field,
        public string $width,
        public SourceLocation $location,
    ) {}

    public function width(): ?LayoutWidth
    {
        return LayoutWidth::tryFrom($this->width);
    }
}
