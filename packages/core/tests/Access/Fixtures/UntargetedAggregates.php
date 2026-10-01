<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fixtures;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Aggregates that name no node a permission is checked on: they do not implement AuthorizedOn.
 */
final readonly class UntargetedAggregates implements Aggregates
{
    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }
}
