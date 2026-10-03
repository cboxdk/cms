<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;

/**
 * The query action of approvals.pending.
 *
 * @implements QueryAction<PendingApprovals, NothingFound>
 */
#[Action(handles: PendingApprovals::class)]
final readonly class PendingApprovalsAction implements QueryAction
{
    use FindsNothing;
}
