<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Fixtures\Surface;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test-only query probe.agent_cards: the first $rows cards of the probe library.
 */
#[QueryType('probe.agent_cards', version: 1)]
final readonly class ReadAgentCards implements Query
{
    public function __construct(
        public int $rows = 1,
    ) {}
}
