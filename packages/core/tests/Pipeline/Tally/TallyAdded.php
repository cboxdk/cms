<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * The test-only mutation of tally.add: a tally is raised by an amount.
 */
final readonly class TallyAdded implements Mutation
{
    public function __construct(
        public TallyId $tally,
        public int $amount,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->tally;
    }
}
