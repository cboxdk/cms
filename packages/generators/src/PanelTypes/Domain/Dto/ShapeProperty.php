<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A member of an object of a contract's JSON Schema: its key, its shape, and whether the document
 * always has it.
 */
#[Internal]
final readonly class ShapeProperty
{
    public function __construct(
        public string $name,
        public JsonShape $shape,
        public bool $required,
    ) {}
}
