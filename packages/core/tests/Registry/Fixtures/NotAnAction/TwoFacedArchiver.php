<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * #[Action] on a class that implements both WriteAction and QueryAction.
 *
 * @implements WriteAction<ArchiveNote, NoAggregates>
 * @implements QueryAction<ArchiveNote, NothingFound>
 */
#[Action(handles: ArchiveNote::class)]
final readonly class TwoFacedArchiver implements QueryAction, WriteAction
{
    use FindsNothing;
    use PlansNothing;
}
