<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The key a replacement replaces: a field type, a class or a command and version
 * (contributions.v1.json, `#/$defs/replacement`).
 */
#[Internal]
final readonly class ReplacementProp
{
    public function __construct(public string $key) {}
}
