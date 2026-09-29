<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What AddTallyAction::resolve() read: each tally the command raises, at its version or absent.
 */
final readonly class TallyAggregates implements Aggregates
{
    /** @var list<ReadVersion> */
    public array $tallies;

    public function __construct(ReadVersion ...$tallies)
    {
        $this->tallies = array_values($tallies);
    }

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(...$this->tallies);
    }
}
