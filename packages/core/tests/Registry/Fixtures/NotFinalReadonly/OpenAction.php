<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * #[Action] on a readonly class that is not final, for the command beside it.
 *
 * @implements WriteAction<MutableCommand, NoAggregates>
 */
#[Action(handles: MutableCommand::class)]
readonly class OpenAction implements WriteAction
{
    use PlansNothing;
}
