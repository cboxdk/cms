<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;

/**
 * Not public API. Only the core packages use it; addons may not (GUARDRAILS 2.3).
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Stable]
final readonly class Internal {}
