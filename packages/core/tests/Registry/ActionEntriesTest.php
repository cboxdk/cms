<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/*
 * The registry's action and query entries check their values, so an entry read from a damaged
 * cache fails as one built wrongly in code does (PRD 13.2).
 */

it('names what each kind of action handles', function (): void {
    expect(array_map(static fn (ActionKind $kind): string => $kind->value.' '.$kind->input(), ActionKind::cases()))->toBe(['write command', 'query query']);
});

it('keeps an action entry\'s values', function (): void {
    $entry = new ActionEntry('App\Save', 'acme/a', ActionKind::Write, new CommandName('a.save'), 3, 'App\SaveNote', [Surface::Rest, Surface::Cli]);

    expect([$entry->class, $entry->package, $entry->kind, $entry->command->value, $entry->commandVersion, $entry->commandClass, $entry->surfaces])
        ->toBe(['App\Save', 'acme/a', ActionKind::Write, 'a.save', 3, 'App\SaveNote', [Surface::Rest, Surface::Cli]])
        ->and($entry->exposes(Surface::Cli))->toBeTrue()
        ->and($entry->exposes(Surface::Mcp))->toBeFalse();
});

it('refuses an action entry with a value it cannot hold', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidRegistryEntry::class, $message);
})->with([
    'version 0' => [static fn (): ActionEntry => new ActionEntry('App\Save', 'acme/a', ActionKind::Write, new CommandName('a.save'), 0, 'App\SaveNote', []), 'Action "App\Save" handles version 0 of "a.save". Versions start at 1.'],
    'an action class that is not one' => [static fn (): ActionEntry => new ActionEntry('App\\', 'acme/a', ActionKind::Write, new CommandName('a.save'), 1, 'App\SaveNote', []), 'The action class "App\\" is not a fully qualified class name.'],
    'a query class that is not one' => [static fn (): ActionEntry => new ActionEntry('App\Find', 'acme/a', ActionKind::Query, new CommandName('a.find'), 1, '1Find', []), 'The action query class "1Find" is not a fully qualified class name.'],
    'a package that is not one' => [static fn (): ActionEntry => new ActionEntry('App\Save', 'acme', ActionKind::Write, new CommandName('a.save'), 1, 'App\SaveNote', []), 'The package "acme" is not a Composer package name.'],
    'surfaces out of order' => [static fn (): ActionEntry => new ActionEntry('App\Save', 'acme/a', ActionKind::Write, new CommandName('a.save'), 1, 'App\SaveNote', [Surface::Cli, Surface::Rest]), 'Action "App\Save" lists the surfaces cli, rest. Each surface is listed once, in the order rest, inertia, mcp, cli.'],
    'a surface twice' => [static fn (): ActionEntry => new ActionEntry('App\Save', 'acme/a', ActionKind::Write, new CommandName('a.save'), 1, 'App\SaveNote', [Surface::Mcp, Surface::Mcp]), 'Action "App\Save" lists the surfaces mcp, mcp.'],
    'a discovered action\'s surfaces out of order' => [static fn (): DiscoveredAction => new DiscoveredAction('App\Save', 'acme/a', ActionKind::Write, 'App\SaveNote', [Surface::Mcp, Surface::Inertia]), 'Action "App\Save" lists the surfaces mcp, inertia.'],
    'a discovered action\'s handled class that is not one' => [static fn (): DiscoveredAction => new DiscoveredAction('App\Save', 'acme/a', ActionKind::Write, 'App\\\\SaveNote', []), 'The class the action handles "App\\\\SaveNote" is not a fully qualified class name.'],
    'a discovered action\'s package that is not one' => [static fn (): DiscoveredAction => new DiscoveredAction('App\Save', 'Acme', ActionKind::Write, 'App\SaveNote', []), 'The package "Acme" is not a Composer package name.'],
    'a discovered action class that is not one' => [static fn (): DiscoveredAction => new DiscoveredAction('', 'acme/a', ActionKind::Write, 'App\SaveNote', []), 'The action class "" is not a fully qualified class name.'],
    'query version 0' => [static fn (): QueryEntry => new QueryEntry(new CommandName('a.find'), 0, 'App\Find', 'acme/a'), 'Query "a.find" has version 0. Versions start at 1.'],
    'a query class that is not a class' => [static fn (): QueryEntry => new QueryEntry(new CommandName('a.find'), 1, 'App Find', 'acme/a'), 'The query class "App Find" is not a fully qualified class name.'],
    'a query package that is not one' => [static fn (): QueryEntry => new QueryEntry(new CommandName('a.find'), 1, 'App\Find', 'a'), 'The package "a" is not a Composer package name.'],
]);

it('accepts no surfaces and every surface in order', function (): void {
    expect(new DiscoveredAction('App\Save', 'acme/a', ActionKind::Write, 'App\SaveNote', [])->surfaces)->toBe([])
        ->and(new DiscoveredAction('App\Save', 'acme/a', ActionKind::Write, 'App\SaveNote', Surface::cases())->surfaces)->toBe(Surface::cases());
});
