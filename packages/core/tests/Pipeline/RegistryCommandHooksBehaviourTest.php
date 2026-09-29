<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Illuminate\Container\Container;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandHooksBehaviour against the registry the application reads: each hook an entry of the
 * compiled hooks registry, its instance bound in a container under a class name of its own, so
 * the same hook class can be registered for several commands.
 */
final class RegistryCommandHooksBehaviourTest extends TestCase
{
    use CommandHooksBehaviour;

    #[Override]
    protected function commandHooks(array $hooks): CommandHooks
    {
        $container = new Container;
        $entries = [];

        foreach ($hooks as $index => [$command, $version, $hook]) {
            $alias = 'Acme\\Hooks\\Hook'.$index;
            $container->instance($alias, $hook->hook);
            $entries[] = new HookEntry($alias, $hook->package, $command, $version, RenameProbe::class, $hook->phase, $hook->priority, $hook->budgetMs);
        }

        return new RegistryCommandHooks(new CompiledRegistry([], $entries), $container);
    }
}
