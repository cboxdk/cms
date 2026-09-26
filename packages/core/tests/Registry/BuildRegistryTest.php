<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Elsewhere\Misplaced;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\TrimNoteTitle;
use PHPUnit\Framework\Assert;

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * Builds and expects the build to fail, returning the failure.
 *
 * @param  list<ScanRoot>  $roots
 */
function failedRegistryBuild(string $directory, array $roots): RegistryBuildFailed
{
    try {
        RegistryFixtures::builder($directory)->build($roots);
    } catch (RegistryBuildFailed $failed) {
        return $failed;
    }

    Assert::fail('The build did not fail.');
}

it('registers exactly the fixture action, command and hook from the fixture scan root', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);

    expect($registry->actions)->toEqual([
        new ActionEntry(CreateNoteAction::class, RegistryFixtures::PACKAGE, [Surface::Rest, Surface::Cli]),
    ])
        ->and($registry->actions[0]->surfaces)->toBe([Surface::Rest, Surface::Cli])
        ->and($registry->commands)->toEqual([
            new CommandEntry('fixture.note.create', 1, CreateNote::class, RegistryFixtures::PACKAGE),
        ])
        ->and($registry->hooks)->toEqual([
            new HookEntry(TrimNoteTitle::class, RegistryFixtures::PACKAGE, 'fixture.note.create', 1, CreateNote::class, Phase::Transform, 10, 5),
        ]);
});

it('writes the three files, and reading them back gives the registry that was built', function (): void {
    $directory = RegistryFixtures::scratch();
    $built = RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);

    $actions = RegistryFixtures::load($directory.'/actions.php');

    expect($actions)->toBe([
        'entries' => [
            ['class' => CreateNoteAction::class, 'package' => RegistryFixtures::PACKAGE, 'surfaces' => ['rest', 'cli']],
        ],
        'format' => 1,
        'registry' => 'actions',
    ]);
});

it('writes three empty registries when there are no scan roots', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build([]);

    expect($registry->actions)->toBe([])
        ->and($registry->commands)->toBe([])
        ->and($registry->hooks)->toBe([]);

    foreach (RegistryName::cases() as $name) {
        expect(RegistryFixtures::load($directory.'/'.$name->fileName()))
            ->toBe(['entries' => [], 'format' => 1, 'registry' => $name->value]);
    }
});

it('removes the subscriber, slot and schema files an earlier version wrote', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);

    foreach (['subscribers', 'slots', 'schema'] as $registry) {
        file_put_contents($directory.'/'.$registry.'.php', sprintf("<?php return ['entries' => [], 'format' => 1, 'registry' => '%s'];\n", $registry));
    }

    $built = RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);
});

it('gives byte-identical files when it builds twice, into the same or another directory', function (): void {
    $directory = RegistryFixtures::scratch();
    $roots = [RegistryFixtures::root('Valid')];
    $other = RegistryFixtures::scratch();

    RegistryFixtures::builder($directory)->build($roots);
    $first = RegistryFixtures::hashes($directory);
    RegistryFixtures::builder($directory)->build($roots);
    $second = RegistryFixtures::hashes($directory);
    RegistryFixtures::builder($other)->build(array_reverse([...$roots, RegistryFixtures::root('Valid')]));
    $elsewhere = RegistryFixtures::hashes($other);

    expect($first)->toHaveCount(3)
        ->and($second)->toBe($first)
        ->and($elsewhere)->toBe($first);
});

it('keeps no temporary files next to the cache', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);
    RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php']);
});

it('refuses two classes with the same command name and version, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('DuplicateCommand')]);

    expect($failed->codes())->toBe([BuildErrorCode::DuplicateCommand])
        ->and($failed->getMessage())->toContain('[registry_duplicate_command]')
        ->toContain('Command "x.y" version 1')
        ->toContain('FirstShape')
        ->toContain('SecondShape')
        ->and(is_dir($directory))->toBeFalse();
});

it('leaves an existing cache as it was when a build fails', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build([RegistryFixtures::root('Valid')]);
    $before = RegistryFixtures::hashes($directory);

    failedRegistryBuild($directory, [RegistryFixtures::root('Valid'), RegistryFixtures::root('DuplicateCommand')]);

    expect(RegistryFixtures::hashes($directory))->toBe($before);
});

it('refuses a hook for a command class that no scan root registers', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('UnknownHookCommand')]);

    expect($failed->codes())->toBe([BuildErrorCode::UnknownHookCommand])
        ->and($failed->getMessage())->toContain('[registry_unknown_hook_command]')->toContain(CreateNote::class);
});

it('accepts the same hook once the command\'s scan root is declared', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build([
        RegistryFixtures::root('UnknownHookCommand', 'acme/orphans'),
        RegistryFixtures::root('Valid'),
    ]);

    expect(array_map(static fn (HookEntry $hook): string => $hook->phase->value.' '.$hook->package, $registry->hooks))
        ->toBe(['transform '.RegistryFixtures::PACKAGE, 'validate acme/orphans']);
});

it('reports attributes whose arguments are invalid, each with its class', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('InvalidAttribute')]);

    expect($failed->codes())->toBe([BuildErrorCode::InvalidAttribute, BuildErrorCode::InvalidAttribute])
        ->and($failed->getMessage())
        ->toContain('#[Hook] on Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\HookOnMissingClass')
        ->toContain('is not a class')
        ->toContain('#[Action] on Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\RepeatedSurface')
        ->toContain('declared more than once');
});

it('refuses an attribute on an abstract class', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('AbstractAction')]);

    expect($failed->codes())->toBe([BuildErrorCode::NotAConcreteClass])
        ->and($failed->getMessage())->toContain('BaseAction')->toContain('an abstract class');
});

it('refuses a class the autoloader cannot find', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('Unloadable')]);

    expect($failed->codes())->toBe([BuildErrorCode::ClassNotLoadable])
        ->and($failed->getMessage())->toContain(Misplaced::class)->toContain('PSR-4');
});

it('refuses a directory that two packages declare', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('Valid'), RegistryFixtures::root('Valid', 'acme/copy')]);

    expect(array_unique(array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes())))->toBe(['registry_class_in_two_roots'])
        ->and($failed->problems)->toHaveCount(4);
});

it('refuses a scan root that is not a directory', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [RegistryFixtures::root('Missing')]);

    expect($failed->codes())->toBe([BuildErrorCode::InvalidScanRoot])
        ->and($failed->getMessage())->toContain('Fixtures/Missing');
});

it('lists every problem of one build, sorted by code', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, [
        RegistryFixtures::root('UnknownHookCommand'),
        RegistryFixtures::root('DuplicateCommand'),
        RegistryFixtures::root('AbstractAction'),
    ]);

    expect(array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes()))->toBe([
        'registry_duplicate_command',
        'registry_not_a_concrete_class',
        'registry_unknown_hook_command',
    ]);
});
