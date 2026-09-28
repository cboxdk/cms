<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * That a `group` repeats, and how often it may: at most `max_items` times (default 500, the most a
 * repeated field holds, PRD 11.6).
 */
#[Internal]
final readonly class GroupRepeat
{
    public const int DEFAULT_MAX_ITEMS = 500;

    public function __construct(
        public ?int $minItems,
        public int $maxItems,
    ) {}
}
