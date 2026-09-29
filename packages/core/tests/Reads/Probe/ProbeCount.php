<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * A result without content: how many cards the library holds.
 */
final readonly class ProbeCount implements Result
{
    public function __construct(
        public int $cards,
    ) {}
}
