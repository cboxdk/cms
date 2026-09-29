<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test-only query probe.read: the first $rows cards of the probe library. It costs one per row.
 */
#[QueryType('probe.read', version: 2)]
final readonly class ReadProbe implements Query
{
    public function __construct(
        public int $rows = 1,
    ) {}
}
