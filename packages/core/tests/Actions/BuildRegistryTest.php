<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeDeclarationScanner;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction\FirstPinner;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction\SecondPinner;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Elsewhere\Misplaced;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NeitherFinalNorReadonly\PlainCommand;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction\PlainArchiver;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction\TwoFacedArchiver;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\MutableCommand;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\MutableQuery;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\OpenAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\LabelWriter;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\MissingWriter;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\NoteLabel;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\RenameNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\RenameReader;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownSurface\ShareNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteTitle;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\TrimNoteTitle;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Core\Tests\Registry\UndefinedSurfaceRoot;
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
        ->and($registry->count(RegistryName::Actions))->toBe(2)
        ->and($scanner->scanned)->toBe([$roots])
        ->and($cache->stored())->toBe($registry)
        ->and($cache->writes)->toBe(1);
});

it('writes nothing when the scan found a problem, and keeps the cache that was there', function (): void {
    $problem = new BuildProblem(BuildErrorCode::ClassNotLoadable, 'Loading Acme\\Broken failed.');
    $scanner = new FakeDeclarationScanner(['/srv/broken/src' => new Discovery([], [], [$problem])]);
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
        '/srv/one/src' => new Discovery([new CommandEntry(new CommandName('x.y'), 1, CreateNote::class, 'acme/one')], [], []),
        '/srv/two/src' => new Discovery([new CommandEntry(new CommandName('x.y'), 1, NoteTitle::class, 'acme/two')], [], []),
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

it('registers exactly the fixture command, hook and actions from the fixture scan root', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect($registry->commands)->toEqual([
        new CommandEntry(new CommandName('fixture.note.create'), 1, CreateNote::class, RegistryFixtures::PACKAGE),
    ])
        ->and($registry->hooks)->toEqual([
            new HookEntry(TrimNoteTitle::class, RegistryFixtures::PACKAGE, new CommandName('fixture.note.create'), 1, CreateNote::class, Phase::Transform, 10, 5),
        ])
        ->and($registry->actions)->toEqual([
            new ActionEntry(CreateNoteAction::class, RegistryFixtures::PACKAGE, ActionKind::Write, new CommandName('fixture.note.create'), 1, CreateNote::class, [Surface::Rest, Surface::Mcp]),
            new ActionEntry(FindNoteAction::class, RegistryFixtures::PACKAGE, ActionKind::Query, new CommandName('fixture.note.find'), 1, FindNote::class, []),
        ]);
});

it('gives the kernel the action of a command read back from the cache', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    $read = RegistryFixtures::cache($directory)->read();

    expect($read->action(new CommandName('fixture.note.create'), 1)?->class)->toBe(CreateNoteAction::class)
        ->and($read->actionFor(FindNote::class)?->class)->toBe(FindNoteAction::class)
        ->and($read->actionFor(FindNote::class)?->kind)->toBe(ActionKind::Query)
        ->and($read->action(new CommandName('fixture.note.create'), 2))->toBeNull();
});

it('keeps the command name as the CommandName value that the hooks and the idempotency scope join on', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    $read = RegistryFixtures::cache($directory)->read();
    $name = new CommandName('fixture.note.create');

    expect($read->commands[0]->name)->toEqual($name)
        ->and($read->hooks[0]->command)->toEqual($name)
        ->and($read->commands[0]->name->equals($read->hooks[0]->command))->toBeTrue();
});

it('writes the three files, and reading them back gives the registry that was built', function (): void {
    $directory = RegistryFixtures::scratch();
    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);

    $actions = RegistryFixtures::load($directory.'/actions.php');
    $commands = RegistryFixtures::load($directory.'/commands.php');
    $hooks = RegistryFixtures::load($directory.'/hooks.php');
    Assert::assertIsArray($actions);
    Assert::assertIsArray($commands);
    Assert::assertIsArray($hooks);

    expect($commands)->toBe([
        'build' => $hooks['build'],
        'entries' => [
            ['class' => CreateNote::class, 'name' => 'fixture.note.create', 'package' => RegistryFixtures::PACKAGE, 'version' => 1],
        ],
        'format' => 4,
        'registry' => 'commands',
    ])
        ->and($actions)->toBe([
            'build' => $hooks['build'],
            'entries' => [
                [
                    'class' => CreateNoteAction::class,
                    'command' => 'fixture.note.create',
                    'command_class' => CreateNote::class,
                    'command_version' => 1,
                    'kind' => 'write',
                    'package' => RegistryFixtures::PACKAGE,
                    'surfaces' => ['rest', 'mcp'],
                ],
                [
                    'class' => FindNoteAction::class,
                    'command' => 'fixture.note.find',
                    'command_class' => FindNote::class,
                    'command_version' => 1,
                    'kind' => 'query',
                    'package' => RegistryFixtures::PACKAGE,
                    'surfaces' => [],
                ],
            ],
            'format' => 4,
            'registry' => 'actions',
        ])
        ->and($commands['build'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('replaces the actions.php of format 2, which listed actions without the command or query they handle', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/actions.php', "<?php return ['build' => '', 'entries' => [], 'format' => 2, 'registry' => 'actions'];\n");

    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(array_map(static fn (RegistryName $name): string => $name->fileName(), RegistryName::cases()))->toBe(['actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::load($directory.'/actions.php'))->toMatchArray(['format' => 4, 'registry' => 'actions'])
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);
});

it('writes three empty registries when there are no scan roots', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots);

    expect($registry->commands)->toBe([])
        ->and($registry->hooks)->toBe([])
        ->and($registry->actions)->toBe([]);

    $build = hash('sha256', "actions => [];\ncommands => [];\nhooks => [];\n");

    foreach (RegistryName::cases() as $name) {
        expect(RegistryFixtures::load($directory.'/'.$name->fileName()))
            ->toBe(['build' => $build, 'entries' => [], 'format' => 4, 'registry' => $name->value]);
    }
});

it('removes the subscriber, slot and schema files an earlier version wrote', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);

    foreach (['subscribers', 'slots', 'schema'] as $registry) {
        file_put_contents($directory.'/'.$registry.'.php', sprintf("<?php return ['entries' => [], 'format' => 1, 'registry' => '%s'];\n", $registry));
    }

    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
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

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php']);
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
        ->toContain('#[Command] on Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\OneSegmentCommand')
        ->toContain('must be dot-separated snake_case segments');
});

it('refuses an attribute on an abstract class', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('AbstractCommand')));

    expect($failed->codes())->toBe([BuildErrorCode::NotAConcreteClass])
        ->and($failed->getMessage())->toContain('BaseCommand')->toContain('an abstract class');
});

it('refuses a command, a query and an action that are not final readonly classes', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NotFinalReadonly')));

    expect($failed->codes())->toBe([BuildErrorCode::NotFinalReadonly, BuildErrorCode::NotFinalReadonly, BuildErrorCode::NotFinalReadonly])
        ->and($failed->getMessage())->toContain('[registry_not_final_readonly]')
        ->toContain('#[Command] on '.MutableCommand::class.' ('.RegistryFixtures::PACKAGE.') is not readonly')
        ->toContain('#[Query] on '.MutableQuery::class.' ('.RegistryFixtures::PACKAGE.') is not readonly')
        ->toContain('#[Action] on '.OpenAction::class.' ('.RegistryFixtures::PACKAGE.') is not final. A command, query or action is a final readonly class (GUARDRAILS 2.1).')
        ->toContain('final readonly class')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a command that is neither final nor readonly in one problem', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NeitherFinalNorReadonly')));

    expect($failed->codes())->toBe([BuildErrorCode::NotFinalReadonly])
        ->and($failed->getMessage())->toContain(PlainCommand::class.' ('.RegistryFixtures::PACKAGE.') is not final and not readonly');
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
        ->and($failed->problems)->toHaveCount(6);
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
        RegistryFixtures::root('AbstractCommand'),
    ));

    expect(array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes()))->toBe([
        'registry_duplicate_command',
        'registry_not_a_concrete_class',
        'registry_unknown_hook_command',
    ]);
});

it('refuses an #[Action] on a class that implements neither WriteAction nor QueryAction, or both', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NotAnAction')));

    expect($failed->codes())->toBe([BuildErrorCode::NotAnAction, BuildErrorCode::NotAnAction])
        ->and($failed->getMessage())
        ->toContain('[registry_not_an_action] #[Action] on '.PlainArchiver::class.' ('.RegistryFixtures::PACKAGE.') sits on a class that implements neither WriteAction nor QueryAction.')
        ->toContain('[registry_not_an_action] #[Action] on '.TwoFacedArchiver::class.' ('.RegistryFixtures::PACKAGE.') sits on a class that implements both WriteAction and QueryAction.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses an action whose command or query is not a registered one of its kind', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('UnknownActionCommand')));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownActionCommand, BuildErrorCode::UnknownActionCommand, BuildErrorCode::UnknownActionCommand])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_action_command] Action '.LabelWriter::class.' ('.RegistryFixtures::PACKAGE.') is a WriteAction and handles '.NoteLabel::class.', which is not a command any scan root registers. A WriteAction handles a command class declared with #[Command] in a registered scan root')
        ->toContain('Action '.MissingWriter::class.' ('.RegistryFixtures::PACKAGE.') is a WriteAction and handles Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\NoSuchCommand, which is not a command any scan root registers.')
        ->toContain('Action '.RenameReader::class.' ('.RegistryFixtures::PACKAGE.') is a QueryAction and handles '.RenameNote::class.', which is a registered command, not a query. A QueryAction handles a query class declared with #[Query]')
        ->and(is_dir($directory))->toBeFalse();
});

it('accepts an action once the scan root of its command is declared', function (): void {
    $elsewhere = new DiscoveredAction('Acme\\Extra\\CreateNoteElsewhere', 'acme/extra', ActionKind::Write, CreateNote::class, [Surface::Cli]);
    $scanner = new FakeDeclarationScanner([
        '/srv/notes/src' => new Discovery(RegistryFixtures::validDiscovery()->commands, [], []),
        '/srv/extra/src' => new Discovery([], [], [], [], [$elsewhere]),
    ]);
    $build = new BuildRegistry($scanner, new RegistryCompiler, new FakeRegistryCache);
    $extra = new ScanRoot('acme/extra', '/srv/extra/src');

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots($extra)))
        ->toThrow(RegistryBuildFailed::class, '[registry_unknown_action_command] Action Acme\\Extra\\CreateNoteElsewhere (acme/extra) is a WriteAction and handles '.CreateNote::class.', which is not a command any scan root registers.')
        ->and($build->build(new ScanRoots($extra, new ScanRoot('acme/notes', '/srv/notes/src')))->actions)->toEqual([
            new ActionEntry('Acme\\Extra\\CreateNoteElsewhere', 'acme/extra', ActionKind::Write, new CommandName('fixture.note.create'), 1, CreateNote::class, [Surface::Cli]),
        ]);
});

it('refuses two actions for one command, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('DuplicateAction')));

    expect($failed->codes())->toBe([BuildErrorCode::DuplicateAction])
        ->and($failed->getMessage())
        ->toContain('[registry_duplicate_action] "fixture.note.pin" version 1 is handled by '.FirstPinner::class.' ('.RegistryFixtures::PACKAGE.') and '.SecondPinner::class.' ('.RegistryFixtures::PACKAGE.'). A command or query has one action')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a surface that is a string rather than a case of Surface', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('UnknownSurface')));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownSurface])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_surface] #[Action] on '.ShareNoteAction::class.' ('.RegistryFixtures::PACKAGE.') lists a surface that does not exist: #[Action] lists "graphql", which is not a surface.')
        ->toContain('The surfaces are the cases of Cbox\Cms\Contracts\Attributes\Surface: Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a surface that names a case Surface does not have', function (): void {
    $directory = RegistryFixtures::scratch();
    $root = UndefinedSurfaceRoot::create();
    $failed = failedRegistryBuild($directory, new ScanRoots(new ScanRoot('acme/graphql', $root)));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownSurface])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_surface] #[Action] on '.UndefinedSurfaceRoot::NAMESPACE.'\ShareByGraphql (acme/graphql) lists a surface that does not exist: Undefined constant Cbox\Cms\Contracts\Attributes\Surface::Graphql.');
});

it('refuses a command and a query that share a name and version', function (): void {
    $scanner = new FakeDeclarationScanner(['/srv/notes/src' => new Discovery(
        [new CommandEntry(new CommandName('note.find'), 1, CreateNote::class, 'acme/notes')],
        [],
        [],
        [new QueryEntry(new CommandName('note.find'), 1, FindNote::class, 'acme/notes')],
    )]);
    $build = new BuildRegistry($scanner, new RegistryCompiler, new FakeRegistryCache);

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots(new ScanRoot('acme/notes', '/srv/notes/src'))))
        ->toThrow(RegistryBuildFailed::class, '[registry_duplicate_command] Command "note.find" version 1 is declared by '.CreateNote::class.' (acme/notes) and '.FindNote::class.' (acme/notes).');
});
