<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One text of an addon's catalogue in the active locale (contributions.v1.json, `#/$defs/text`):
 * the translation key a contribution names and its text, which the host fills the parameters
 * {name} of before it is shown.
 */
#[Internal]
final readonly class TextProp
{
    public function __construct(
        public string $key,
        public string $text,
    ) {}
}
