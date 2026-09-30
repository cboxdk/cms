<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Fixtures\Surface;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeAggregates;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Override;

/**
 * The test-only write action the REST tests compile a route for: probe.rename, exposed on REST and
 * the Inertia profile, which the panel and REST keep in parity. It plans as RenameProbeAction does.
 *
 * @implements WriteAction<RenameProbe, ProbeAggregates>
 */
#[Action(handles: RenameProbe::class, surfaces: [Surface::Rest, Surface::Inertia])]
final readonly class RestRenameAction implements WriteAction
{
    private RenameProbeAction $action;

    public function __construct(ProbeShelf $shelf = new ProbeShelf)
    {
        $this->action = new RenameProbeAction($shelf);
    }

    /**
     * @param  RenameProbe  $command
     */
    #[Override]
    public function resolve(Command $command): ProbeAggregates
    {
        return $this->action->resolve($command);
    }

    /**
     * @param  RenameProbe  $command
     * @param  ProbeAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return $this->action->plan($command, $aggregates);
    }
}
