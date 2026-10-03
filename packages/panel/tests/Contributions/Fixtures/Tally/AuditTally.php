<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The test addon's query tally.audit, whose permission its audit contribution requires of a
 * viewer.
 */
#[QueryType('tally.audit', version: 1)]
final readonly class AuditTally implements Query {}
