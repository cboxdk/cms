<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * That a `group` repeats, and how often it may.
 */
#[Internal]
final readonly class GroupRepeat
{
    public function __construct(
        public ?int $minItems,
        public ?int $maxItems,
    ) {}
}
