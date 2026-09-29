<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;

/**
 * A query action that handles a command instead of a query.
 *
 * @implements QueryAction<FindsLabel, NothingFound>
 */
#[Action(handles: RenameNote::class)]
final readonly class RenameReader implements QueryAction
{
    use FindsNothing;
}
