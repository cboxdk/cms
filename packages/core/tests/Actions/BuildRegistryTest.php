<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
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
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeDeclarationScanner;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction\FirstPinner;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction\SecondPinner;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateSubscription\FirstIndexer;
use Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateSubscription\SecondIndexer;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Elsewhere\Misplaced;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NeitherFinalNorReadonly\PlainCommand;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAHook\MisphasedStamper;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAHook\PlainStamper;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction\PlainArchiver;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction\TwoFacedArchiver;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotASubscriber\PlainListener;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\MutableCommand;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\MutableQuery;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly\OpenAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalSubscriber\MutableSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalSubscriber\OpenSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\LabelWriter;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\MissingWriter;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\NoteLabel;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\RenameNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand\RenameReader;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\BadlyNamedEvent;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\BadTypeSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\MissingEventSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\NotAnEventSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\PlainValue;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownLane\StringLaneSubscriber;
use Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownSurface\ShareNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\IndexNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\InvalidateNoteFragments;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteArchived;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteRenamed;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteTitle;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NotifyNoteWebhooks;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\TrimNoteTitle;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Core\Tests\Registry\UndefinedLaneRoot;
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
        ->and($registry->count(RegistryName::Subscribers))->toBe(3)
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

it('registers exactly the fixture command, hook, actions and subscribers from the fixture scan root', function (): void {
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
        ])
        ->and($registry->subscribers)->toEqual([
            new SubscriberEntry(InvalidateNoteFragments::class, RegistryFixtures::PACKAGE, new SubscriptionName('fixture.fragments'), Lane::Critical, new ProjectionName('fixture_fragments'), [
                new SubscribedEvent(NoteArchived::class, new EventType('fixture.note_archived', 2)),
                new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1)),
            ]),
            new SubscriberEntry(IndexNote::class, RegistryFixtures::PACKAGE, new SubscriptionName('fixture.search'), Lane::Standard, new ProjectionName('fixture_search'), [
                new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1)),
            ]),
            new SubscriberEntry(NotifyNoteWebhooks::class, RegistryFixtures::PACKAGE, new SubscriptionName('fixture.webhooks'), Lane::External, null, [
                new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1)),
                new SubscribedEvent(NoteRenamed::class, new EventType('fixture.note_renamed', 1)),
            ]),
        ]);
});

/**
 * @param  list<ProjectionName>  $projections
 * @return list<string>
 */
function builtProjectionNames(array $projections): array
{
    return array_map(static fn (ProjectionName $projection): string => $projection->value, $projections);
}

it('tells the kernel which projections an event class affects, read back from the cache', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    $read = RegistryFixtures::cache($directory)->read();

    expect(builtProjectionNames($read->projectionsFor(NoteCreated::class)))->toBe(['fixture_fragments', 'fixture_search'])
        ->and(builtProjectionNames($read->projectionsFor(NoteArchived::class)))->toBe(['fixture_fragments'])
        ->and($read->projectionsFor(NoteRenamed::class))->toBe([])
        ->and($read->projectionsFor(NoteTitle::class))->toBe([])
        ->and(array_map(static fn (SubscriberEntry $subscriber): string => $subscriber->name->value, $read->subscribersOf(NoteCreated::class)))
        ->toBe(['fixture.fragments', 'fixture.search', 'fixture.webhooks']);
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

it('writes the four files, and reading them back gives the registry that was built', function (): void {
    $directory = RegistryFixtures::scratch();
    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'subscribers.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);

    $actions = RegistryFixtures::load($directory.'/actions.php');
    $commands = RegistryFixtures::load($directory.'/commands.php');
    $hooks = RegistryFixtures::load($directory.'/hooks.php');
    $subscribers = RegistryFixtures::load($directory.'/subscribers.php');
    Assert::assertIsArray($actions);
    Assert::assertIsArray($subscribers);
    Assert::assertIsArray($commands);
    Assert::assertIsArray($hooks);

    expect($commands)->toBe([
        'build' => $hooks['build'],
        'entries' => [
            ['class' => CreateNote::class, 'name' => 'fixture.note.create', 'package' => RegistryFixtures::PACKAGE, 'version' => 1],
        ],
        'format' => 5,
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
            'format' => 5,
            'registry' => 'actions',
        ])
        ->and($subscribers)->toBe([
            'build' => $hooks['build'],
            'entries' => [
                [
                    'class' => InvalidateNoteFragments::class,
                    'events' => [
                        ['class' => NoteArchived::class, 'name' => 'fixture.note_archived', 'version' => 2],
                        ['class' => NoteCreated::class, 'name' => 'fixture.note_created', 'version' => 1],
                    ],
                    'lane' => 'critical',
                    'name' => 'fixture.fragments',
                    'package' => RegistryFixtures::PACKAGE,
                    'projection' => 'fixture_fragments',
                ],
                [
                    'class' => IndexNote::class,
                    'events' => [
                        ['class' => NoteCreated::class, 'name' => 'fixture.note_created', 'version' => 1],
                    ],
                    'lane' => 'standard',
                    'name' => 'fixture.search',
                    'package' => RegistryFixtures::PACKAGE,
                    'projection' => 'fixture_search',
                ],
                [
                    'class' => NotifyNoteWebhooks::class,
                    'events' => [
                        ['class' => NoteCreated::class, 'name' => 'fixture.note_created', 'version' => 1],
                        ['class' => NoteRenamed::class, 'name' => 'fixture.note_renamed', 'version' => 1],
                    ],
                    'lane' => 'external',
                    'name' => 'fixture.webhooks',
                    'package' => RegistryFixtures::PACKAGE,
                    'projection' => null,
                ],
            ],
            'format' => 5,
            'registry' => 'subscribers',
        ])
        ->and($commands['build'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('replaces the actions.php of format 2, which listed actions without the command or query they handle', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/actions.php', "<?php return ['build' => '', 'entries' => [], 'format' => 2, 'registry' => 'actions'];\n");

    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(array_map(static fn (RegistryName $name): string => $name->fileName(), RegistryName::cases()))->toBe(['actions.php', 'commands.php', 'hooks.php', 'subscribers.php'])
        ->and(RegistryFixtures::load($directory.'/actions.php'))->toMatchArray(['format' => 5, 'registry' => 'actions'])
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'subscribers.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($built);
});

it('writes four empty registries when there are no scan roots', function (): void {
    $directory = RegistryFixtures::scratch();
    $registry = RegistryFixtures::builder($directory)->build(new ScanRoots);

    expect($registry->commands)->toBe([])
        ->and($registry->hooks)->toBe([])
        ->and($registry->actions)->toBe([])
        ->and($registry->subscribers)->toBe([]);

    $build = hash('sha256', "actions => [];\ncommands => [];\nhooks => [];\nsubscribers => [];\n");

    foreach (RegistryName::cases() as $name) {
        expect(RegistryFixtures::load($directory.'/'.$name->fileName()))
            ->toBe(['build' => $build, 'entries' => [], 'format' => 5, 'registry' => $name->value]);
    }
});

it('removes the slot and schema files an earlier version wrote, and replaces its subscribers.php of format 1', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);

    foreach (['subscribers', 'slots', 'schema'] as $registry) {
        file_put_contents($directory.'/'.$registry.'.php', sprintf("<?php return ['entries' => [], 'format' => 1, 'registry' => '%s'];\n", $registry));
    }

    $built = RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'subscribers.php'])
        ->and(RegistryFixtures::load($directory.'/subscribers.php'))->toMatchArray(['format' => 5, 'registry' => 'subscribers'])
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

    expect($first)->toHaveCount(4)
        ->and($second)->toBe($first)
        ->and($elsewhere)->toBe($first);
});

it('keeps no temporary files next to the cache', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));
    RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'subscribers.php']);
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
        ->toContain('#[Action] on '.OpenAction::class.' ('.RegistryFixtures::PACKAGE.') is not final. A command, query, action or subscriber is a final readonly class (GUARDRAILS 2.1).')
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
        ->and($failed->problems)->toHaveCount(12);
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

it('refuses a #[Hook] on a class that does not implement the interface of its phase', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NotAHook')));

    expect($failed->codes())->toBe([BuildErrorCode::NotAHook, BuildErrorCode::NotAHook])
        ->and($failed->getMessage())
        ->toContain('[registry_not_a_hook] #[Hook] on '.MisphasedStamper::class.' ('.RegistryFixtures::PACKAGE.') runs in the validate phase and does not implement Cbox\Cms\Contracts\Hooks\ValidateHook, the interface of that phase (GUARDRAILS 2.4).')
        ->toContain('[registry_not_a_hook] #[Hook] on '.PlainStamper::class.' ('.RegistryFixtures::PACKAGE.') runs in the authorize phase and does not implement Cbox\Cms\Contracts\Hooks\AuthorizeHook, the interface of that phase (GUARDRAILS 2.4).')
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

it('refuses a subscriber that is not a final readonly class, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NotFinalSubscriber')));

    expect($failed->codes())->toBe([BuildErrorCode::NotFinalReadonly, BuildErrorCode::NotFinalReadonly])
        ->and($failed->getMessage())
        ->toContain('[registry_not_final_readonly] #[Subscription] on '.MutableSubscriber::class.' ('.RegistryFixtures::PACKAGE.') is not readonly. A command, query, action or subscriber is a final readonly class (GUARDRAILS 2.1). Declare it as final readonly class MutableSubscriber.')
        ->toContain('[registry_not_final_readonly] #[Subscription] on '.OpenSubscriber::class.' ('.RegistryFixtures::PACKAGE.') is not final.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a #[Subscription] on a class that does not implement Subscriber', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('NotASubscriber')));

    expect($failed->codes())->toBe([BuildErrorCode::NotASubscriber])
        ->and($failed->getMessage())
        ->toContain('[registry_not_a_subscriber] #[Subscription] on '.PlainListener::class.' ('.RegistryFixtures::PACKAGE.') sits on a class that does not implement Cbox\Cms\Contracts\Subscribers\Subscriber.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses an event class that does not exist, is not an event or whose type fails', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('UnknownEvent')));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownEvent, BuildErrorCode::UnknownEvent, BuildErrorCode::UnknownEvent])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_event] #[Subscription] on '.MissingEventSubscriber::class.' ('.RegistryFixtures::PACKAGE.') lists an event it cannot receive: #[Subscription] lists the event class "Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownEvent\NoSuchEvent", which does not exist.')
        ->toContain('[registry_unknown_event] #[Subscription] on '.NotAnEventSubscriber::class.' ('.RegistryFixtures::PACKAGE.') lists an event it cannot receive: #[Subscription] lists the class "'.PlainValue::class.'", which does not implement Cbox\Cms\Contracts\Events\Event.')
        ->toContain('[registry_unknown_event] #[Subscription] on '.BadTypeSubscriber::class.' ('.RegistryFixtures::PACKAGE.') lists an event it cannot receive: the type() of the event class '.BadlyNamedEvent::class.' failed: An event type name is dot-separated snake_case segments')
        ->toContain('A subscriber lists classes that implement Cbox\Cms\Contracts\Events\Event.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a lane that is a string rather than a case of Lane', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('UnknownLane')));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownLane])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_lane] #[Subscription] on '.StringLaneSubscriber::class.' ('.RegistryFixtures::PACKAGE.') names a lane that does not exist: #[Subscription] names the lane "urgent", which is not a lane.')
        ->toContain('The lanes are the cases of Cbox\Cms\Contracts\Subscribers\Lane: Lane::Critical, Lane::Standard, Lane::External, Lane::Revalidate, Lane::Background.')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses a lane that names a case Lane does not have', function (): void {
    $directory = RegistryFixtures::scratch();
    $root = UndefinedLaneRoot::create();
    $failed = failedRegistryBuild($directory, new ScanRoots(new ScanRoot('acme/urgent', $root)));

    expect($failed->codes())->toBe([BuildErrorCode::UnknownLane])
        ->and($failed->getMessage())
        ->toContain('[registry_unknown_lane] #[Subscription] on '.UndefinedLaneRoot::NAMESPACE.'\UrgentSubscriber (acme/urgent) names a lane that does not exist: Undefined constant Cbox\Cms\Contracts\Subscribers\Lane::Urgent.');
});

it('refuses two subscribers with the same subscription name, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $failed = failedRegistryBuild($directory, new ScanRoots(RegistryFixtures::root('DuplicateSubscription')));

    expect($failed->codes())->toBe([BuildErrorCode::DuplicateSubscription])
        ->and($failed->getMessage())
        ->toContain('[registry_duplicate_subscription] Subscription "fixture.index" is declared by '.FirstIndexer::class.' ('.RegistryFixtures::PACKAGE.') and '.SecondIndexer::class.' ('.RegistryFixtures::PACKAGE.').')
        ->and(is_dir($directory))->toBeFalse();
});

it('refuses the same subscription name in two packages through the fake scanner too', function (): void {
    $event = [new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1))];
    $scanner = new FakeDeclarationScanner([
        '/srv/one/src' => new Discovery([], [], [], [], [], [new SubscriberEntry('Acme\One\Index', 'acme/one', new SubscriptionName('search.index'), Lane::Standard, null, $event)]),
        '/srv/two/src' => new Discovery([], [], [], [], [], [new SubscriberEntry('Acme\Two\Index', 'acme/two', new SubscriptionName('search.index'), Lane::Background, null, $event)]),
    ]);
    $build = new BuildRegistry($scanner, new RegistryCompiler, new FakeRegistryCache);

    expect(static fn (): CompiledRegistry => $build->build(new ScanRoots(new ScanRoot('acme/one', '/srv/one/src'), new ScanRoot('acme/two', '/srv/two/src'))))
        ->toThrow(RegistryBuildFailed::class, '[registry_duplicate_subscription] Subscription "search.index" is declared by Acme\One\Index (acme/one) and Acme\Two\Index (acme/two).');
});
