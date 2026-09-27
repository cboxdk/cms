<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Something the inventory rule finds that is not an extension point, and why. An exclusion without
 * a reason excludes nothing and is a finding, and so is one that names nothing the rule finds.
 */
final readonly class Exclusion
{
    public function __construct(
        public string $name,
        public string $reason,
    ) {}

    public function hasReason(): bool
    {
        return trim($this->reason) !== '';
    }
}
