<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;

/**
 * Not public API. Only the core packages use it; addons may not (GUARDRAILS 2.3).
 *
 * On a class it covers the whole class. On a method or a class constant of a #[Stable] or
 * #[Experimental] class it covers that member only. The testkit's PHPStan extensions report
 * every use outside the Cbox\Cms namespace as cboxCms.internalUse.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
#[Stable]
final readonly class Internal {}
