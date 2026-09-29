<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Illuminate\Container\Container;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * WriteActionsBehaviour against the registry the application reads, with the probe action
 * registered for probe.rename and bound in a container.
 */
final class RegistryWriteActionsBehaviourTest extends TestCase
{
    use WriteActionsBehaviour;

    #[Override]
    protected function writeActions(RenameProbeAction $action): WriteActions
    {
        $container = new Container;
        $container->instance(RenameProbeAction::class, $action);

        return new RegistryWriteActions(new CompiledRegistry([], [], [
            new ActionEntry(RenameProbeAction::class, 'cboxdk/cms', ActionKind::Write, new CommandName('probe.rename'), 1, RenameProbe::class, []),
        ]), $container);
    }
}
