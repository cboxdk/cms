<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\QueryProbe;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test-only query probe.see_entries: every entry the read's context lets it see. With $fail,
 * the action throws after it has looked, as a read that breaks halfway would.
 */
#[QueryType('probe.see_entries', version: 1)]
final readonly class SeeEntries implements Query
{
    public function __construct(
        public bool $fail = false,
    ) {}
}
