<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;

/**
 * Public API without a compatibility promise yet. Addons may use it and must expect it to
 * change in a minor release (GUARDRAILS 2.3).
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Stable]
final readonly class Experimental {}
