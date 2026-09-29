<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * One of two write actions for the same command.
 *
 * @implements WriteAction<PinNote, NoAggregates>
 */
#[Action(handles: PinNote::class)]
final readonly class FirstPinner implements WriteAction
{
    use PlansNothing;
}
