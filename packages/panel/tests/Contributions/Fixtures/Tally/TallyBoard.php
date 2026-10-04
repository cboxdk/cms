<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test addon's query tally.board, which its page reads its data with: it takes no input, as
 * the query of a page must, because a page has no props to take it from.
 */
#[QueryType('tally.board', version: 1)]
final readonly class TallyBoard implements Query {}
