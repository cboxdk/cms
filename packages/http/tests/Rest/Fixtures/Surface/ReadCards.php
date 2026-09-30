<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Fixtures\Surface;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test-only query the REST tests compile a route for: probe.cards, the first $rows cards of the
 * probe library. It costs one per row.
 */
#[QueryType('probe.cards', version: 1)]
final readonly class ReadCards implements Query
{
    public function __construct(
        public int $rows = 1,
    ) {}
}
