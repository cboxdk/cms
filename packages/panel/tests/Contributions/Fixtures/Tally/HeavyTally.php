<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test addon's query tally.heavy, which costs more than any actor's budget.
 */
#[QueryType('tally.heavy', version: 1)]
final readonly class HeavyTally implements Query
{
    public function __construct(public string $note) {}
}
