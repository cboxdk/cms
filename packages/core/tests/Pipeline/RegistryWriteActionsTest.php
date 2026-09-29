<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeBinding;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Illuminate\Container\Container;

/*
 * RegistryWriteActions takes only a write action for a command: a query action registered for the
 * class, or a class that does not implement WriteAction, is a stale registry, not an action.
 */

function registryActions(ActionKind $kind, string $class, Container $container): RegistryWriteActions
{
    return new RegistryWriteActions(new CompiledRegistry([], [], [
        new ActionEntry($class, 'cboxdk/cms', $kind, new CommandName('probe.rename'), 1, RenameProbe::class, []),
    ]), $container);
}

it('refuses a command whose registered action is a query action', function (): void {
    expect(static fn (): ActionBinding => registryActions(ActionKind::Query, ProbeShelf::class, new Container)->for(new PipelineWorld()->command()))
        ->toThrow(UnknownCommand::class, 'No write action handles the command '.RenameProbe::class.'.');
});

it('refuses a registered class that is not a write action', function (): void {
    expect(static fn (): ActionBinding => registryActions(ActionKind::Write, ProbeShelf::class, new Container)->for(new PipelineWorld()->command()))
        ->toThrow(UnknownCommand::class, 'The action '.ProbeShelf::class.' registered for the command '.RenameProbe::class.' does not implement WriteAction. Run cms:build.');
});

it('refuses a version below 1 in a binding', function (): void {
    expect(static fn (): ActionBinding => ProbeBinding::of(new RenameProbeAction(new ProbeShelf), 0))
        ->toThrow(UnknownCommand::class, 'The command probe.rename has version 0. Versions start at 1.');
});
