<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Actions\MapHooks;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookMapRequest;
use Cbox\Cms\Core\Registry\Domain\Dto\VersionHooks;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownCommand;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\InspectedRegistry;

/*
 * MapHooks, behind cms:hooks (PRD 13.2): the hook map of a command, each registered version lowest
 * first with its hooks in the order they run, phase in pipeline order, then priority with the
 * lowest first, then package, then class (PRD 6.3), with their budgets. A name no command has, a
 * query's included, is refused, and so is a registry cache that cannot be read.
 */

/**
 * @return list<array{int, list<string>}>
 */
function hookMap(string $command): array
{
    $map = new MapHooks(InspectedRegistry::cache())->map(new HookMapRequest(new CommandName($command)));

    return array_map(static fn (VersionHooks $version): array => [
        $version->version,
        array_map(static fn (HookEntry $hook): string => sprintf('%s %d %s %s %d', $hook->phase->value, $hook->priority, $hook->package, $hook->class, $hook->budgetMs), $version->hooks),
    ], $map->versions);
}

it('maps every version of a command with its hooks in the order they run and their budgets', function (): void {
    expect(hookMap('note.create'))->toBe([
        [1, [
            'authorize 50 acme/notes Acme\Notes\CheckQuota 2',
            'transform 0 acme/notes Acme\Notes\SlugTitle 1',
            'transform 1 acme/audit Acme\Audit\TrimTitle 3',
            'transform 1 acme/notes Acme\Notes\TrimTitle 4',
            'validate 0 acme/notes Acme\Notes\RequireTitle 5',
        ]],
        [2, ['validate 10 acme/notes Acme\Notes\RequireBody 20']],
    ]);
});

it('maps a command without hooks as its versions with none', function (): void {
    expect(hookMap('note.archive'))->toBe([[1, []]]);
});

it('gives the hooks in the order the command pipeline takes them from the registry', function (): void {
    $registry = InspectedRegistry::compiled();
    $map = new MapHooks(InspectedRegistry::cache())->map(new HookMapRequest(new CommandName('note.create')));

    expect($map->command->value)->toBe('note.create')
        ->and($map->versions[0]->hooks)->toEqual($registry->hooksOf(new CommandName('note.create'), 1))
        ->and(array_map(static fn (HookEntry $hook): Phase => $hook->phase, $map->versions[0]->hooks))
        ->toBe([Phase::Authorize, Phase::Transform, Phase::Transform, Phase::Transform, Phase::Validate]);
});

it('refuses a name that no registered command has, and names the registered commands', function (): void {
    expect(fn (): mixed => new MapHooks(InspectedRegistry::cache())->map(new HookMapRequest(new CommandName('note.delete'))))
        ->toThrow(UnknownCommand::class, 'No registered command is named note.delete. The registered commands are note.archive, note.create.');
});

it('refuses the name of a query, which runs no hooks', function (): void {
    expect(fn (): mixed => new MapHooks(InspectedRegistry::cache())->map(new HookMapRequest(new CommandName('note.find'))))
        ->toThrow(UnknownCommand::class, 'note.find is a query.');
});

it('refuses every name when the registry holds no commands', function (): void {
    $cache = new FakeRegistryCache;
    $cache->write(CompiledRegistry::empty());

    expect(fn (): mixed => new MapHooks($cache)->map(new HookMapRequest(new CommandName('note.create'))))
        ->toThrow(UnknownCommand::class, 'The registry holds no commands.');
});

it('refuses a registry cache that is missing or damaged', function (): void {
    $damaged = InspectedRegistry::cache();
    $damaged->damage();
    $request = new HookMapRequest(new CommandName('note.create'));

    expect(fn (): mixed => new MapHooks(new FakeRegistryCache)->map($request))->toThrow(RegistryCacheMissing::class)
        ->and(fn (): mixed => new MapHooks($damaged)->map($request))->toThrow(MalformedRegistryCache::class);
});
