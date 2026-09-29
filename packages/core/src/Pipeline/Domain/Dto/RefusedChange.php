<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Hooks\FieldChange;

/**
 * A change of a transform hook the kernel refuses (PRD 6.2 phase 4, invariant 12), with why in
 * plain language.
 */
#[Internal]
final readonly class RefusedChange
{
    public function __construct(
        public FieldChange $change,
        public string $reason,
    ) {}
}
