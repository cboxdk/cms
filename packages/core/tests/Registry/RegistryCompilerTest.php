<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use PHPUnit\Framework\Assert;

/**
 * @param  list<CommandEntry>  $commands
 * @param  list<DiscoveredHook>  $hooks
 */
function registryCompilerFailure(array $commands, array $hooks = []): RegistryBuildFailed
{
    try {
        new RegistryCompiler()->compile(new Discovery($commands, $hooks, []));
    } catch (RegistryBuildFailed $failed) {
        return $failed;
    }

    Assert::fail('The compiler accepted the declarations.');
}

it('sorts commands by name and version, and hooks by command, phase, priority, package and class', function (): void {
    $commands = [
        new CommandEntry(new CommandName('b.b'), 2, 'App\B2', 'acme/b'),
        new CommandEntry(new CommandName('b.b'), 1, 'App\B1', 'acme/b'),
        new CommandEntry(new CommandName('a.a'), 1, 'App\A1', 'acme/a'),
    ];
    $hooks = [
        new DiscoveredHook('App\Hooks\Late', 'acme/z', 'App\A1', Phase::Validate, 0, 1),
        new DiscoveredHook('App\Hooks\SamePriorityB', 'acme/b', 'App\A1', Phase::Transform, 5, 1),
        new DiscoveredHook('App\Hooks\SamePriorityA', 'acme/b', 'App\A1', Phase::Transform, 5, 1),
        new DiscoveredHook('App\Hooks\OtherPackage', 'acme/a', 'App\A1', Phase::Transform, 5, 1),
        new DiscoveredHook('App\Hooks\Negative', 'acme/z', 'App\A1', Phase::Transform, -10, 1),
        new DiscoveredHook('App\Hooks\Authorize', 'acme/z', 'App\A1', Phase::Authorize, 100, 1),
        new DiscoveredHook('App\Hooks\SecondVersion', 'acme/a', 'App\B2', Phase::Authorize, 0, 1),
        new DiscoveredHook('App\Hooks\FirstVersion', 'acme/a', 'App\B1', Phase::Validate, 0, 1),
    ];
    $registry = new RegistryCompiler()->compile(new Discovery($commands, $hooks, []));

    expect(array_map(static fn (CommandEntry $command): string => $command->name->value.' v'.$command->version, $registry->commands))->toBe(['a.a v1', 'b.b v1', 'b.b v2'])
        ->and(array_map(static fn (HookEntry $hook): string => $hook->class, $registry->hooks))->toBe([
            'App\Hooks\Authorize',
            'App\Hooks\Negative',
            'App\Hooks\OtherPackage',
            'App\Hooks\SamePriorityA',
            'App\Hooks\SamePriorityB',
            'App\Hooks\Late',
            'App\Hooks\FirstVersion',
            'App\Hooks\SecondVersion',
        ])
        ->and($registry->hooks[6]->command)->toEqual(new CommandName('b.b'))
        ->and($registry->hooks[6]->commandVersion)->toBe(1)
        ->and($registry->hooks[7]->commandVersion)->toBe(2);
});

it('gives the same registry for the declarations in any order', function (): void {
    $commands = [new CommandEntry(new CommandName('a.a'), 1, 'App\A1', 'acme/a'), new CommandEntry(new CommandName('a.a'), 2, 'App\A2', 'acme/a')];
    $hooks = [
        new DiscoveredHook('App\H1', 'acme/a', 'App\A1', Phase::Transform, 1, 1),
        new DiscoveredHook('App\H2', 'acme/a', 'App\A2', Phase::Transform, 1, 1),
    ];

    $forwards = new RegistryCompiler()->compile(new Discovery($commands, $hooks, []));
    $backwards = new RegistryCompiler()->compile(new Discovery(array_reverse($commands), array_reverse($hooks), []));

    expect($backwards)->toEqual($forwards);
});

it('resolves a hook\'s command class without regard to case, as PHP does', function (): void {
    $registry = new RegistryCompiler()->compile(new Discovery(
        [new CommandEntry(new CommandName('a.a'), 1, 'App\Commands\Create', 'acme/a')],
        [new DiscoveredHook('App\H', 'acme/a', 'app\commands\CREATE', Phase::Validate, 0, 1)],
        [],
    ));

    expect($registry->hooks[0]->commandClass)->toBe('App\Commands\Create');
});

it('allows the same command name in different versions, and refuses the same version twice', function (): void {
    $failed = registryCompilerFailure([
        new CommandEntry(new CommandName('entry.release'), 1, 'App\ReleaseV1', 'acme/a'),
        new CommandEntry(new CommandName('entry.release'), 2, 'App\ReleaseV2', 'acme/a'),
        new CommandEntry(new CommandName('entry.release'), 2, 'App\ReleaseAgain', 'acme/b'),
    ]);

    expect($failed->problems)->toEqual([
        new BuildProblem(BuildErrorCode::DuplicateCommand, 'Command "entry.release" version 2 is declared by App\ReleaseAgain (acme/b) and App\ReleaseV2 (acme/a). A name and version belong to one class: give the new shape the next version, or rename one of the commands.'),
    ]);
});

it('reports a hook for an unregistered command', function (): void {
    $failed = registryCompilerFailure([], [new DiscoveredHook('App\H', 'acme/a', 'App\Missing', Phase::Validate, 0, 1)]);

    expect($failed->codes())->toBe([BuildErrorCode::UnknownHookCommand])
        ->and($failed->problems[0]->message)->toContain('App\H (acme/a)')->toContain('App\Missing')->toContain('DeclaresScanRoots');
});

it('fails with the scanner\'s problems even when the declarations compile', function (): void {
    $problem = new BuildProblem(BuildErrorCode::ClassNotLoadable, 'Could not load App\X.');

    try {
        new RegistryCompiler()->compile(new Discovery([], [], [$problem]));
        Assert::fail('The compiler accepted a discovery with problems.');
    } catch (RegistryBuildFailed $failed) {
        expect($failed->problems)->toBe([$problem])
            ->and($failed->getMessage())->toBe("The registry was not built, and the cache was left as it was. 1 problem:\n[registry_class_not_loadable] Could not load App\\X.");
    }
});
