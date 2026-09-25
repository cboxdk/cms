<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;

/**
 * Public API with a compatibility promise: it changes only under semver (GUARDRAILS 2.3).
 *
 * Every class, interface, trait and enum in packages/src carries exactly one of Stable,
 * Experimental and Internal. An architecture test enforces it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Stable]
final readonly class Stable {}
