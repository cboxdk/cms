<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Adapter\RegistryQueryActions;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeLibrary;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use Illuminate\Container\Container;

/*
 * The registry's query actions refuse what is not a query action for the query: a write action
 * registered for the query's class, and a registered class that is not a QueryAction; and a
 * binding refuses a version below 1.
 */

it('refuses a query whose registered action is a write action', function (): void {
    $actions = new RegistryQueryActions(new CompiledRegistry([], [], [
        new ActionEntry(RenameProbeAction::class, 'cboxdk/cms', ActionKind::Write, new CommandName('probe.read'), 2, ReadProbe::class, []),
    ]), new Container);

    expect(fn (): QueryBinding => $actions->for(new ReadProbe))->toThrow(UnknownQuery::class, 'No query action handles the query '.ReadProbe::class);
});

it('refuses a registered class that does not implement QueryAction', function (): void {
    $container = new Container;
    $container->instance(RenameProbeAction::class, new RenameProbeAction(new ProbeShelf));
    $actions = new RegistryQueryActions(new CompiledRegistry([], [], [
        new ActionEntry(RenameProbeAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('probe.read'), 2, ReadProbe::class, []),
    ]), $container);

    expect(fn (): QueryBinding => $actions->for(new ReadProbe))->toThrow(UnknownQuery::class, sprintf('The action %s registered for the query %s does not implement QueryAction. Run cms:build.', RenameProbeAction::class, ReadProbe::class));
});

it('refuses a binding with a version below 1', function (): void {
    expect(fn (): QueryBinding => ProbeQueryBinding::of(new ReadProbeAction(new ProbeLibrary), version: 0))
        ->toThrow(UnknownQuery::class, 'The query probe.read has version 0. Versions start at 1.');
});
