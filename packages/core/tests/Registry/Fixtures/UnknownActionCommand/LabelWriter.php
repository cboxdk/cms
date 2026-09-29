<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * A write action that handles a class without #[Command].
 *
 * @implements WriteAction<RenameNote, NoAggregates>
 */
#[Action(handles: NoteLabel::class)]
final readonly class LabelWriter implements WriteAction
{
    use PlansNothing;
}
