<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;

/**
 * The fixture query action for the registry tests, on no surface.
 *
 * @implements QueryAction<FindNote, NothingFound>
 */
#[Action(handles: FindNote::class)]
final readonly class FindNoteAction implements QueryAction
{
    use FindsNothing;
}
