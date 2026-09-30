<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeAggregates;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use LogicException;
use Override;

/**
 * The write action the contract kernel binds to every command of the registry. The command it gets
 * is the real command, read from the surface's document by the command's generated codec; it
 * plans what the probe action plans for a probe.rename with the fields the scenario gives
 * (ContractKernel::probe()), so the kernel's validation, dry run and commit run on a plan for any
 * command. It reads each aggregate the command expects at the version the command expects, so the
 * kernel's version check passes and a version conflict comes only from the commit.
 *
 * @implements WriteAction<Command, Aggregates>
 */
final readonly class ContractAction implements WriteAction
{
    public function __construct(private ContractKernel $kernel) {}

    #[Override]
    public function resolve(Command $command): ProbeAggregates
    {
        $expected = $command instanceof ExpectsVersions ? $command->expectedVersions()->reads : [];

        return new RenameProbeAction($this->kernel->exposed->world->shelf, extraReads: $expected)->resolve($this->kernel->probe());
    }

    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if (! $aggregates instanceof ProbeAggregates) {
            throw new LogicException(sprintf('The contract action plans only on what it resolved, and got %s.', $aggregates::class));
        }

        return new RenameProbeAction($this->kernel->exposed->world->shelf)->plan($this->kernel->probe(), $aggregates);
    }
}
