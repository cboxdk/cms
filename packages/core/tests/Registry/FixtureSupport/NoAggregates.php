<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What the registry fixtures' write actions read: nothing. The registry tests read only the
 * actions' declarations, never call them.
 */
final readonly class NoAggregates implements Aggregates
{
    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }
}
