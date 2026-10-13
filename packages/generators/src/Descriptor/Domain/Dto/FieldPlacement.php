<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\LayoutWidth;

/**
 * The place of one top-level field in a section of the compiled layout (gap G2): the field's
 * path as the editor descriptor addresses it, a handle or `ext.<namespace>.<handle>`, and its
 * width preset.
 */
#[Internal]
final readonly class FieldPlacement
{
    public function __construct(
        public string $path,
        public LayoutWidth $width,
    ) {}
}
