<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test addon's query tally.notes, which its slot fill reads its data with: the input note is
 * taken from the point's props by name.
 */
#[QueryType('tally.notes', version: 1)]
final readonly class TallyNotes implements Query
{
    public function __construct(public string $note) {}
}
