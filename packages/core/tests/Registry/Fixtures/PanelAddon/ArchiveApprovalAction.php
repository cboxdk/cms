<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * The write action of approvals.archive.
 *
 * @implements WriteAction<ArchiveApproval, NoAggregates>
 */
#[Action(handles: ArchiveApproval::class)]
final readonly class ArchiveApprovalAction implements WriteAction
{
    use PlansNothing;
}
