<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One property of a command document an action fills from the point's props, by a JSON pointer
 * into them (contributions.v1.json, `#/$defs/prefill`).
 */
#[Internal]
final readonly class PrefillProp
{
    public function __construct(
        public string $property,
        public string $pointer,
    ) {}
}
