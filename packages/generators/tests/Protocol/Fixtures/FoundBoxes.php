<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of the probe query probe.find_boxes: the first box found, or null, and how many
 * there are.
 */
final readonly class FoundBoxes implements Result
{
    public function __construct(
        public ?Box $first,
        public int $count,
    ) {}
}
