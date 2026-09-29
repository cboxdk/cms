<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Adapter\RegistryQueryActions;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use Illuminate\Container\Container;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * QueryActionsBehaviour against the registry the application reads, with the probe action
 * registered for probe.read and bound in a container.
 */
final class RegistryQueryActionsBehaviourTest extends TestCase
{
    use QueryActionsBehaviour;

    #[Override]
    protected function queryActions(ReadProbeAction $action): QueryActions
    {
        $container = new Container;
        $container->instance(ReadProbeAction::class, $action);

        return new RegistryQueryActions(new CompiledRegistry([], [], [
            new ActionEntry(ReadProbeAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('probe.read'), 2, ReadProbe::class, []),
        ]), $container);
    }
}
