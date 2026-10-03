<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * The write action of approvals.request.
 *
 * @implements WriteAction<RequestApproval, NoAggregates>
 */
#[Action(handles: RequestApproval::class, surfaces: [Surface::Inertia])]
final readonly class RequestApprovalAction implements WriteAction
{
    use PlansNothing;
}
