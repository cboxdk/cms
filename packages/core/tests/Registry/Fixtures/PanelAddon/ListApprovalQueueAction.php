<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;

/**
 * The query action of approvals.queue.
 *
 * @implements QueryAction<ListApprovalQueue, NothingFound>
 */
#[Action(handles: ListApprovalQueue::class)]
final readonly class ListApprovalQueueAction implements QueryAction
{
    use FindsNothing;
}
