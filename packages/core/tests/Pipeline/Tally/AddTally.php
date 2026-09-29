<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as PipelineCommand;

/**
 * A test-only command for the commit on Postgres: raises the tally by the amount, creating it when
 * it does not exist, and then the tally $next by the same amount when one is given, all in one
 * changeset.
 */
#[Command('tally.add', version: 1)]
final readonly class AddTally implements PipelineCommand
{
    public function __construct(
        public TallyId $tally,
        public int $amount,
        public ?TallyId $next = null,
    ) {}
}
