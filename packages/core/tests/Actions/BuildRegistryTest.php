<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeDeclarationScanner;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Elsewhere\Misplaced;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\TrimNoteTitle;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use PHPUnit\Framework\Assert;

/*
 * cms:build's action. The first cases call it with its ScanRoots and the fake scanner and cache
 * (GUARDRAILS 9); DeclarationScannerBehaviour and RegistryCacheBehaviour hold the fakes to
 * AttributeScanner and FileRegistryCache. The cases after them build the fixture directories with
 * the real scanner and cache, end to end.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

it('compiles what the scanner finds in the roots it is given, writes it and returns it', function (): void {
    $scanner = new FakeDeclarationScanner(['/srv/notes/src' => RegistryFixtures::validDiscovery()]);
    $cache = new FakeRegistryCache('/srv/app/bootstrap/cache/cms');
    $roots = new ScanRoots(new ScanRoot('acme/notes', '/srv/notes/src'));

    $registry = new BuildRegistry($scanner, new RegistryCompiler, $cache)->build($roots);

    expect($registry)->toEqual(new RegistryCompiler()->compile(RegistryFixtures::validDiscovery('acme/notes')))
        ->and($registry->count(RegistryName::Hooks))->toBe(1)
        ->and($scanner->scanned)->toBe([$roots])
        ->and($cache->stored())->toBe($registry)
        ->and($cache->writes)->toBe(1);
});

it('writes nothing when the scan found a problem, and keeps the cache that was there', function (): void {
    $problem = new BuildProblem(BuildErrorCode::ClassNotLoadable, 'Loading Acme\\Broken failed.');
    $scanner = new FakeDeclarationScanner(['/srv/broken/src' => new Discovery([], [], [], [$problem])]);
    $cache = new FakeRegistryCache;
    $cache->write(CompiledRegistry::empty());

    $build = new BuildRegistry($scanner, new RegistryCompiler, $cache);

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots(new ScanRoot('acme/broken', '/srv/broken/src'))))
        ->toThrow(RegistryBuildFailed::class, '[registry_class_not_loadable] Loading Acme\\Broken failed.')
        ->and($cache->writes)->toBe(1)
        ->and($cache->read())->toEqual(CompiledRegistry::empty());
});

it('writes nothing when two roots declare the same command', function (): void {
    $scanner = new FakeDeclarationScanner([
        '/srv/one/src' => new Discovery([], [new CommandEntry('x.y', 1, CreateNote::class, 'acme/one')], [], []),
        '/srv/two/src' => new Discovery([], [new CommandEntry('x.y', 1, CreateNoteAction::class, 'acme/two')], [], []),
    ]);
    $cache = new FakeRegistryCache;
    $build = new BuildRegistry($scanner, new RegistryCompiler, $cache);

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots(new ScanRoot('acme/one', '/srv/one/src'), new ScanRoot('acme/two', '/srv/two/src'))))
        ->toThrow(RegistryBuildFailed::class, '[registry_duplicate_command]')
        ->and($cache->stored())->toBeNull();
});

it('passes on a cache that cannot be written', function (): void {
    $cache = new FakeRegistryCache('/srv/app/bootstrap/cache/cms');
    $cache->refuseWrites('Permission denied');
    $build = new BuildRegistry(new FakeDeclarationScanner, new RegistryCompiler, $cache);

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots))
        ->toThrow(RegistryCacheUnwritable::class, '/srv/app/bootstrap/cache/cms/actions.php: Permission denied')
        ->and($build->location())->toBe('/srv/app/bootstrap/cache/cms');
});

/**
 * Builds and expects the build to fail, returning the failure.
 */
function failedRegistryBuild(string $directory, ScanRoots $roots): RegistryBuildFailed
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
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

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
    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

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
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots);

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

    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);
});

it('gives byte-identical files when it builds twice, into the same or another directory', function (): void {
    $directory = RegistryFixtures::scratch();
    $roots = new ScanRoots(RegistryFixtures::root('Valid'));
    $other = RegistryFixtures::scratch();

    RegistryFixtures::builder($directory)->build($roots);
    $first = RegistryFixtures::hashes($directory);
    RegistryFixtures::builder($directory)->build($roots);
    $second = RegistryFixtures::hashes($directory);
    RegistryFixtures::builder($other)->build(new ScanRoots(...array_reverse([...$roots->roots, RegistryFixtures::root('Valid')])));
    $elsewhere = RegistryFixtures::hashes($other);

    expect($first)->toHaveCount(3)
        ->and($second)->toBe($first)
        ->and($elsewhere)->toBe($first);
});

it('keeps no temporary files next to the cache', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php']);
});

it('refuses two classes with the same command name and version, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('DuplicateCommand')));

    expect($failed->codes())->toBe([BuildErrorCode::DuplicateCommand])
        ->and($failed->getMessage())->toContain('[registry_duplicate_command]')
        ->toContain('Command "x.y" version 1')
        ->toContain('FirstShape')
        ->toContain('SecondShape')
        ->and(is_dir($directory))->toBeFalse();
});

it('leaves an existing cache as it was when a build fails', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    $before = RegistryFixtures::hashes($directory);

    failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('Valid'), RegistryFixtures::root('DuplicateCommand')));

    expect(RegistryFixtures::hashes($directory))->toBe($before);
});

it('refuses a hook for a command class that no scan root registers', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('UnknownHookCommand')));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownHookCommand])
        ->and($failed->getMessage())->toContain('[registry_unknown_hook_command]')->toContain(CreateNote::class);
});

it('accepts the same hook once the command\'s scan root is declared', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots(
        RegistryFixtures::root('UnknownHookCommand', 'acme/orphans'),
        RegistryFixtures::root('Valid'),
    ));

    expect(array_map(static fn (HookEntry $hook): string => $hook->phase->value.' '.$hook->package, $registry->hooks))
        ->toBe(['transform '.RegistryFixtures::PACKAGE, 'validate acme/orphans']);
});

it('reports attributes whose arguments are invalid, each with its class', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('InvalidAttribute')));

    expect($failed->codes())->toBe([BuildErrorCode::InvalidAttribute, BuildErrorCode::InvalidAttribute])
        ->and($failed->getMessage())
        ->toContain('#[Hook] on Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\HookOnMissingClass')
        ->toContain('is not a class')
        ->toContain('#[Action] on Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\RepeatedSurface')
        ->toContain('declared more than once');
});

it('refuses an attribute on an abstract class', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('AbstractAction')));

    expect($failed->codes())->toBe([BuildErrorCode::NotAConcreteClass])
        ->and($failed->getMessage())->toContain('BaseAction')->toContain('an abstract class');
});

it('refuses a class the autoloader cannot find', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('Unloadable')));

    expect($failed->codes())->toBe([BuildErrorCode::ClassNotLoadable])
        ->and($failed->getMessage())->toContain(Misplaced::class)->toContain('PSR-4');
});

it('refuses a directory that two packages declare', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('Valid'), RegistryFixtures::root('Valid', 'acme/copy')));

    expect(array_unique(array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes())))->toBe(['registry_class_in_two_roots'])
        ->and($failed->problems)->toHaveCount(4);
});

it('refuses a scan root that is not a directory', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('Missing')));

    expect($failed->codes())->toBe([BuildErrorCode::InvalidScanRoot])
        ->and($failed->getMessage())->toContain('Fixtures/Missing');
});

it('lists every problem of one build, sorted by code', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(
        RegistryFixtures::root('UnknownHookCommand'),
        RegistryFixtures::root('DuplicateCommand'),
        RegistryFixtures::root('AbstractAction'),
    ));

    expect(array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes()))->toBe([
        'registry_duplicate_command',
        'registry_not_a_concrete_class',
        'registry_unknown_hook_command',
    ]);
});
