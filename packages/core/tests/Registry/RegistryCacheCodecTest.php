<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use PHPUnit\Framework\Assert;

function codecRegistry(): CompiledRegistry
{
    return new CompiledRegistry(
        [new CommandEntry(new CommandName('note.create'), 1, 'App\Commands\CreateNote', 'acme/notes')],
        [
            new HookEntry('App\Hooks\Trim', 'acme/notes', new CommandName('note.create'), 1, 'App\Commands\CreateNote', Phase::Transform, -5, 3),
            new HookEntry('Acme\Reviews\Hooks\RequireStars', 'acme/cms-reviews', new CommandName('note.create'), 1, 'App\Commands\CreateNote', Phase::Validate, 0, 2, new AddonNamespace('reviews'), ClassificationAccess::Internal),
        ],
        [new ActionEntry('App\Actions\CreateNoteAction', 'acme/notes', ActionKind::Write, new CommandName('note.create'), 1, 'App\Commands\CreateNote', [Surface::Rest, Surface::Mcp])],
        [
            new SubscriberEntry('App\Subscribers\InvalidateNotes', 'acme/notes', new SubscriptionName('notes.fragments'), Lane::Critical, new ProjectionName('fragments'), [
                new SubscribedEvent('App\Events\NoteArchived', new EventType('note.archived', 2)),
                new SubscribedEvent('App\Events\NoteCreated', new EventType('note.created', 1)),
            ]),
            new SubscriberEntry('Acme\Reviews\Subscribers\NotifyReviewers', 'acme/cms-reviews', new SubscriptionName('reviews.notify'), Lane::External, null, [
                new SubscribedEvent('App\Events\NoteCreated', new EventType('note.created', 1)),
            ], new AddonNamespace('reviews')),
        ],
        [
            new SchemaEntry(new AddonNamespace('reviews'), 'acme/cms-reviews', [new ContributedFieldType('reviews:stars')], [new TypeName('reviews:review')], [new TypeName('app:note'), new TypeName('shop:product')], 'Acme\\Reviews\\ReviewsFieldTypes'),
        ],
    );
}

/**
 * What each file of the registry returns, keyed by registry name, as the adapter passes it.
 *
 * @return array<string, mixed>
 */
function codecFiles(CompiledRegistry $registry): array
{
    $files = [];

    foreach (new RegistryCacheCodec()->encode($registry) as $name => $source) {
        $path = tempnam(sys_get_temp_dir(), 'cms-codec-');
        Assert::assertIsString($path);
        file_put_contents($path, $source);
        $files[$name] = RegistryFixtures::load($path);
        unlink($path);
    }

    return $files;
}

/**
 * The array one file returned.
 *
 * @param  array<array-key, mixed>  $files
 * @return array<string, mixed>
 */
function codecFile(array $files, string $name): array
{
    $file = $files[$name];
    Assert::assertIsArray($file);
    $data = [];

    foreach ($file as $key => $value) {
        $data[(string) $key] = $value;
    }

    return $data;
}

/**
 * @param  mixed  $damaged  what a dataset's damage returned: the files, keyed by registry name
 */
function codecFailure(mixed $damaged): MalformedRegistryCache
{
    Assert::assertIsArray($damaged);
    $files = [];

    foreach ($damaged as $name => $file) {
        $files[(string) $name] = $file;
    }

    try {
        new RegistryCacheCodec()->decode($files, '/cache');
    } catch (MalformedRegistryCache $malformed) {
        return $malformed;
    }

    Assert::fail('The codec read a malformed cache.');
}

it('writes the exact bytes of format 7', function (): void {
    $files = new RegistryCacheCodec()->encode(codecRegistry());
    $header = "<?php\n\ndeclare(strict_types=1);\n\n// Written by php artisan cms:build from the declared scan roots and addon manifests (PRD 13.2).\n// Do not edit and do not commit; run cms:build again instead.\n\n";

    expect(array_keys($files))->toBe(['actions', 'commands', 'hooks', 'schema', 'subscribers'])
        ->and($files['actions'])->toBe($header.<<<'PHP'
            return [
                'build' => '29818aef2ad360af2aedd9957bf15f2529961f67d482438b2332cd8e8ce20fdb',
                'entries' => [
                    [
                        'class' => 'App\\Actions\\CreateNoteAction',
                        'command' => 'note.create',
                        'command_class' => 'App\\Commands\\CreateNote',
                        'command_version' => 1,
                        'kind' => 'write',
                        'package' => 'acme/notes',
                        'surfaces' => [
                            'rest',
                            'mcp',
                        ],
                    ],
                ],
                'format' => 7,
                'registry' => 'actions',
            ];

            PHP)
        ->and($files['commands'])->toBe($header.<<<'PHP'
            return [
                'build' => '29818aef2ad360af2aedd9957bf15f2529961f67d482438b2332cd8e8ce20fdb',
                'entries' => [
                    [
                        'class' => 'App\\Commands\\CreateNote',
                        'name' => 'note.create',
                        'package' => 'acme/notes',
                        'version' => 1,
                    ],
                ],
                'format' => 7,
                'registry' => 'commands',
            ];

            PHP)
        ->and($files['hooks'])->toBe($header.<<<'PHP'
            return [
                'build' => '29818aef2ad360af2aedd9957bf15f2529961f67d482438b2332cd8e8ce20fdb',
                'entries' => [
                    [
                        'addon' => null,
                        'budget_ms' => 3,
                        'class' => 'App\\Hooks\\Trim',
                        'command' => 'note.create',
                        'command_class' => 'App\\Commands\\CreateNote',
                        'command_version' => 1,
                        'package' => 'acme/notes',
                        'phase' => 'transform',
                        'priority' => -5,
                        'reads' => null,
                    ],
                    [
                        'addon' => 'reviews',
                        'budget_ms' => 2,
                        'class' => 'Acme\\Reviews\\Hooks\\RequireStars',
                        'command' => 'note.create',
                        'command_class' => 'App\\Commands\\CreateNote',
                        'command_version' => 1,
                        'package' => 'acme/cms-reviews',
                        'phase' => 'validate',
                        'priority' => 0,
                        'reads' => 'internal',
                    ],
                ],
                'format' => 7,
                'registry' => 'hooks',
            ];

            PHP)
        ->and($files['schema'])->toBe($header.<<<'PHP'
            return [
                'build' => '29818aef2ad360af2aedd9957bf15f2529961f67d482438b2332cd8e8ce20fdb',
                'entries' => [
                    [
                        'extends' => [
                            'app:note',
                            'shop:product',
                        ],
                        'field_type_contributor' => 'Acme\\Reviews\\ReviewsFieldTypes',
                        'field_types' => [
                            'reviews:stars',
                        ],
                        'namespace' => 'reviews',
                        'package' => 'acme/cms-reviews',
                        'types' => [
                            'reviews:review',
                        ],
                    ],
                ],
                'format' => 7,
                'registry' => 'schema',
            ];

            PHP)
        ->and($files['subscribers'])->toBe($header.<<<'PHP'
            return [
                'build' => '29818aef2ad360af2aedd9957bf15f2529961f67d482438b2332cd8e8ce20fdb',
                'entries' => [
                    [
                        'addon' => null,
                        'class' => 'App\\Subscribers\\InvalidateNotes',
                        'events' => [
                            [
                                'class' => 'App\\Events\\NoteArchived',
                                'name' => 'note.archived',
                                'version' => 2,
                            ],
                            [
                                'class' => 'App\\Events\\NoteCreated',
                                'name' => 'note.created',
                                'version' => 1,
                            ],
                        ],
                        'lane' => 'critical',
                        'name' => 'notes.fragments',
                        'package' => 'acme/notes',
                        'projection' => 'fragments',
                    ],
                    [
                        'addon' => 'reviews',
                        'class' => 'Acme\\Reviews\\Subscribers\\NotifyReviewers',
                        'events' => [
                            [
                                'class' => 'App\\Events\\NoteCreated',
                                'name' => 'note.created',
                                'version' => 1,
                            ],
                        ],
                        'lane' => 'external',
                        'name' => 'reviews.notify',
                        'package' => 'acme/cms-reviews',
                        'projection' => null,
                    ],
                ],
                'format' => 7,
                'registry' => 'subscribers',
            ];

            PHP)
        ->and(new RegistryCacheCodec()->encode(CompiledRegistry::empty())['commands'])->toBe($header."return [\n    'build' => '".hash('sha256', "actions => [];\ncommands => [];\nhooks => [];\nschema => [];\nsubscribers => [];\n")."',\n    'entries' => [],\n    'format' => 7,\n    'registry' => 'commands',\n];\n");
});

it('reads back what it writes', function (): void {
    expect(new RegistryCacheCodec()->decode(codecFiles(codecRegistry()), '/cache'))->toEqual(codecRegistry())
        ->and(new RegistryCacheCodec()->decode(codecFiles(CompiledRegistry::empty()), '/cache'))->toEqual(CompiledRegistry::empty());
});

it('writes the same bytes for the same registry', function (): void {
    expect(new RegistryCacheCodec()->encode(codecRegistry()))->toBe(new RegistryCacheCodec()->encode(codecRegistry()));
});

it('escapes the backslashes in class names so the file stays valid PHP', function (): void {
    $source = new RegistryCacheCodec()->encode(codecRegistry())['commands'];

    expect($source)->toContain("'class' => 'App\\\\Commands\\\\CreateNote'");
});

it('refuses a malformed cache with the file and the place in it', function (callable $damage, string $file, string $expected): void {
    $files = codecFiles(codecRegistry());
    $files = $damage($files);

    $malformed = codecFailure($files);

    expect($malformed->getMessage())
        ->toStartWith('[registry_cache_malformed] The registry cache file /cache/'.$file)
        ->toContain($expected)
        ->toContain('run php artisan cms:build');
})->with([
    'a missing file' => [static function (array $files): array {
        unset($files['hooks']);

        return $files;
    }, 'hooks.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'a file of format 1, which has no build' => [static function (array $files): array {
        $files['commands'] = ['entries' => [], 'format' => 1, 'registry' => 'commands'];

        return $files;
    }, 'commands.php', 'at format: format 1 is not format 7, which this version of the core reads'],
    'a file of format 2, whose actions had no command' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'format' => 2];

        return $files;
    }, 'actions.php', 'at format: format 2 is not format 7, which this version of the core reads'],
    'a file of format 3, whose cache had no actions.php' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'format' => 3];

        return $files;
    }, 'commands.php', 'at format: format 3 is not format 7, which this version of the core reads'],
    'a file of format 4, whose cache had no subscribers.php' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'format' => 4];

        return $files;
    }, 'hooks.php', 'at format: format 4 is not format 7, which this version of the core reads'],
    'a file of format 5, whose cache had no schema.php' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'format' => 5];

        return $files;
    }, 'subscribers.php', 'at format: format 5 is not format 7, which this version of the core reads'],
    'a file of format 6, whose schema.php named no field type contributor' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'format' => 6];

        return $files;
    }, 'schema.php', 'at format: format 6 is not format 7, which this version of the core reads'],
    'a missing schema.php' => [static function (array $files): array {
        unset($files['schema']);

        return $files;
    }, 'schema.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'a missing subscribers.php' => [static function (array $files): array {
        unset($files['subscribers']);

        return $files;
    }, 'subscribers.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'the wrong registry' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'registry' => 'hooks'];

        return $files;
    }, 'commands.php', "at registry: it names the registry 'hooks', not \"commands\""],
    'an extra key' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'built_at' => 1];

        return $files;
    }, 'commands.php', 'expected the keys build, entries, format, registry, got build, built_at, entries, format, registry'],
    'entries that are not a list' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => ['a' => []]];

        return $files;
    }, 'hooks.php', 'at entries: expected a list, got array'],
    'an entry missing a key' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'entries' => [['class' => 'App\C', 'name' => 'a.b', 'package' => 'acme/a']]];

        return $files;
    }, 'commands.php', 'at entries[0]: expected the keys class, name, package, version, got class, name, package'],
    'a version that is a string' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'entries' => [['class' => 'App\C', 'name' => 'a.b', 'package' => 'acme/a', 'version' => '1']]];

        return $files;
    }, 'commands.php', 'at entries[0].version: expected an integer, got string'],
    'an unknown phase' => [static function (array $files): array {
        $entry = codecHook(['phase' => 'commit']);
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [$entry]];

        return $files;
    }, 'hooks.php', 'at entries[0].phase: "commit" is not a hook phase'],
    'a budget over the limit' => [static function (array $files): array {
        $entry = codecHook(['budget_ms' => 21]);
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [$entry]];

        return $files;
    }, 'hooks.php', 'at entries[0]: Hook "App\H" has a budget of 21 ms'],
    'a command name that is not one' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'entries' => [['class' => 'App\C', 'name' => 'Note.Create', 'package' => 'acme/a', 'version' => 1]]];

        return $files;
    }, 'commands.php', 'at entries[0].name: A command name is dot-separated snake_case segments, for example "entry.release", got "Note.Create". Do not edit'],
    'a hook for a command name that is not one' => [static function (array $files): array {
        $entry = codecHook(['command' => 'note']);
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [$entry]];

        return $files;
    }, 'hooks.php', 'at entries[0].command: A command name is dot-separated snake_case segments, for example "entry.release", got "note". Do not edit'],
    'a command name that is not a string' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'entries' => [['class' => 'App\C', 'name' => 7, 'package' => 'acme/a', 'version' => 1]]];

        return $files;
    }, 'commands.php', 'at entries[0].name: expected a string, got int'],
    'a class name that is not one' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'entries' => [['class' => 'App\\', 'name' => 'a.b', 'package' => 'acme/a', 'version' => 1]]];

        return $files;
    }, 'commands.php', 'is not a fully qualified class name'],
    'a build that is not a sha256' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'build' => 'ABC'];

        return $files;
    }, 'hooks.php', 'at build: "ABC" is not a sha256 in lowercase hex'],
    'a file of another build' => [static function (array $files): array {
        $files['hooks'] = codecFiles(CompiledRegistry::empty())['hooks'];

        return $files;
    }, 'hooks.php', 'at build: it comes from another cms:build than actions.php: the files were read while a build replaced them, or a build stopped before it had replaced them all'],
    'the first file of another build' => [static function (array $files): array {
        $files['actions'] = codecFiles(CompiledRegistry::empty())['actions'];

        return $files;
    }, 'commands.php', 'at build: it comes from another cms:build than actions.php'],
    'entries changed after the build' => [static function (array $files): array {
        $hooks = codecFile($files, 'hooks');
        $entries = $hooks['entries'];
        Assert::assertIsArray($entries);
        $entry = $entries[0];
        Assert::assertIsArray($entry);
        $files['hooks'] = [...$hooks, 'entries' => [[...$entry, 'priority' => 7]]];

        return $files;
    }, 'actions.php', 'at build: the build does not match the entries of the registry files, so they were changed after cms:build wrote them'],
    'an action entry missing a key' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => null])]];

        return $files;
    }, 'actions.php', 'at entries[0]: expected the keys class, command, command_class, command_version, kind, package, surfaces, got class, command, command_class, command_version, kind, package'],
    'an unknown action kind' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['kind' => 'mutation'])]];

        return $files;
    }, 'actions.php', 'at entries[0].kind: "mutation" is not an action kind'],
    'an unknown surface' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => ['rest', 'graphql']])]];

        return $files;
    }, 'actions.php', 'at entries[0].surfaces[1]: "graphql" is not a surface'],
    'a surface that is not a string' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => [1]])]];

        return $files;
    }, 'actions.php', 'at entries[0].surfaces[0]: expected a string, got int'],
    'surfaces that are not a list' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => 'rest'])]];

        return $files;
    }, 'actions.php', 'at entries[0].surfaces: expected a list, got string'],
    'surfaces out of the order of the enum' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => ['mcp', 'rest']])]];

        return $files;
    }, 'actions.php', 'at entries[0]: Action "App\A" lists the surfaces mcp, rest. Each surface is listed once, in the order rest, inertia, mcp, cli.'],
    'a surface listed twice' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['surfaces' => ['cli', 'cli']])]];

        return $files;
    }, 'actions.php', 'at entries[0]: Action "App\A" lists the surfaces cli, cli.'],
    'an action for a command name that is not one' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['command' => 'note'])]];

        return $files;
    }, 'actions.php', 'at entries[0].command: A command name is dot-separated snake_case segments, for example "entry.release", got "note". Do not edit'],
    'an action for version 0' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['command_version' => 0])]];

        return $files;
    }, 'actions.php', 'at entries[0]: Action "App\A" handles version 0 of "a.b". Versions start at 1.'],
    'an action whose command class is not one' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'entries' => [codecAction(['command_class' => 'App\\'])]];

        return $files;
    }, 'actions.php', 'at entries[0]: The action command class "App\" is not a fully qualified class name.'],
    'a subscriber entry missing a key' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['projection' => null])]];

        return $files;
    }, 'subscribers.php', 'at entries[0]: expected the keys addon, class, events, lane, name, package, projection, got addon, class, events, lane, name, package'],
    'a subscriber of an addon whose namespace is reserved' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['addon' => 'app'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].addon: The addon namespace "app" is reserved'],
    'a subscriber of an addon that is not a string' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['addon' => 7])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].addon: expected a string, got int'],
    'a hook entry missing what it reads' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [codecHook(['reads' => null])]];

        return $files;
    }, 'hooks.php', 'at entries[0]: expected the keys addon, budget_ms, class, command, command_class, command_version, package, phase, priority, reads, got addon, budget_ms, class, command, command_class, command_version, package, phase, priority'],
    'a hook of an addon whose namespace is not one' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [codecHook(['addon' => 'Reviews', 'reads' => 'public'])]];

        return $files;
    }, 'hooks.php', 'at entries[0].addon: The addon namespace "Reviews" is not a lowercase letter followed by at most 19 lowercase letters and digits'],
    'a hook that reads a class that is not a classification' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [codecHook(['addon' => 'reviews', 'reads' => 'secret'])]];

        return $files;
    }, 'hooks.php', 'at entries[0].reads: "secret" is not a classification'],
    'a hook of an addon that says nothing of what it reads' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [codecHook(['addon' => 'reviews'])]];

        return $files;
    }, 'hooks.php', 'at entries[0]: Hook "App\H" names an addon but not what it reads.'],
    'a hook that reads but names no addon' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'entries' => [codecHook(['reads' => 'internal'])]];

        return $files;
    }, 'hooks.php', 'at entries[0]: Hook "App\H" names what it reads but no addon.'],
    'a schema entry missing a key' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['extends' => null])]];

        return $files;
    }, 'schema.php', 'at entries[0]: expected the keys extends, field_type_contributor, field_types, namespace, package, types, got field_type_contributor, field_types, namespace, package, types'],
    'a schema entry of a reserved namespace' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['namespace' => 'ext'])]];

        return $files;
    }, 'schema.php', 'at entries[0].namespace: The addon namespace "ext" is reserved'],
    'a field type that is not <namespace>:<handle>' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['field_types' => ['stars']])]];

        return $files;
    }, 'schema.php', 'at entries[0].field_types[0]: The field type "stars" is not <namespace>:<handle>'],
    'field types without their contributor' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [[...codecSchema([]), 'field_type_contributor' => null]]];

        return $files;
    }, 'schema.php', 'at entries[0]: Addon "reviews" has field types but no field type contributor.'],
    'a contributor without field types' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['field_types' => []])]];

        return $files;
    }, 'schema.php', 'at entries[0]: Addon "reviews" has a field type contributor but no field types.'],
    'a contributor that is not a class name' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['field_type_contributor' => 'reviews field types'])]];

        return $files;
    }, 'schema.php', 'at entries[0]: The field type contributor "reviews field types" is not a fully qualified class name.'],
    'a contributor that is not a string' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['field_type_contributor' => 7])]];

        return $files;
    }, 'schema.php', 'at entries[0].field_type_contributor'],
    'a field type of another addon' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['field_types' => ['shop:stars']])]];

        return $files;
    }, 'schema.php', 'at entries[0]: Addon "reviews" lists "shop:stars" among its field types.'],
    'an own type of another owner' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['types' => ['app:review']])]];

        return $files;
    }, 'schema.php', 'at entries[0]: Addon "reviews" lists "app:review" among its types.'],
    'an extension of its own type' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['extends' => ['reviews:review']])]];

        return $files;
    }, 'schema.php', 'at entries[0]: Addon "reviews" lists "reviews:review" among its extended types.'],
    'types out of order' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['extends' => ['shop:product', 'app:note']])]];

        return $files;
    }, 'schema.php', 'at entries[0]: The extended types of addon "reviews" are shop:product, app:note. Each is listed once, sorted by name.'],
    'a type name that is not one' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['types' => ['review']])]];

        return $files;
    }, 'schema.php', 'at entries[0].types[0]: '],
    'types that are not a list' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['types' => 'reviews:review'])]];

        return $files;
    }, 'schema.php', 'at entries[0].types: expected a list, got string'],
    'a schema entry of a package that is not one' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'entries' => [codecSchema(['package' => 'reviews'])]];

        return $files;
    }, 'schema.php', 'at entries[0]: The package "reviews" is not a Composer package name.'],
    'an unknown lane' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['lane' => 'urgent'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].lane: "urgent" is not a lane'],
    'a lane that is not a string' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['lane' => 1])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].lane: expected a string, got int'],
    'a subscription name that is not one' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['name' => 'Notes'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].name: The subscription name "Notes" must be dot-separated snake_case segments'],
    'a projection name that is not one' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['projection' => 'Fragments'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].projection: A projection name is dot-separated snake_case segments of at most 63 characters, for example "fragments" or "acme.search", got "Fragments". Do not edit'],
    'a projection that is not a string' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['projection' => false])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].projection: expected a string, got bool'],
    'events that are not a list' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => 'App\E'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].events: expected a list, got string'],
    'no events' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => []])]];

        return $files;
    }, 'subscribers.php', 'at entries[0]: Subscriber "App\S" receives no event. A subscriber receives at least one.'],
    'an event missing a key' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [['class' => 'App\E', 'name' => 'a.b']]])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].events[0]: expected the keys class, name, version, got class, name'],
    'an event type that is not one' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [['class' => 'App\E', 'name' => 'created', 'version' => 1]]])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].events[0]: An event type name is dot-separated snake_case segments, at least two'],
    'an event version below 1' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [['class' => 'App\E', 'name' => 'a.b', 'version' => 0]]])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].events[0]: An event type version starts at 1, got 0. Do not edit'],
    'an event class that is not one' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [['class' => 'App\\', 'name' => 'a.b', 'version' => 1]]])]];

        return $files;
    }, 'subscribers.php', 'at entries[0].events[0]: The event class "App\" is not a fully qualified class name.'],
    'events out of order' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [
            ['class' => 'App\F', 'name' => 'a.f', 'version' => 1],
            ['class' => 'App\E', 'name' => 'a.e', 'version' => 1],
        ]])]];

        return $files;
    }, 'subscribers.php', 'at entries[0]: Subscriber "App\S" receives the events App\F, App\E. Each event class is listed once, sorted by class.'],
    'an event listed twice' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['events' => [
            ['class' => 'App\E', 'name' => 'a.e', 'version' => 1],
            ['class' => 'app\e', 'name' => 'a.e', 'version' => 1],
        ]])]];

        return $files;
    }, 'subscribers.php', 'Each event class is listed once, sorted by class.'],
    'a subscriber class that is not one' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'entries' => [codecSubscriber(['class' => 'App\\'])]];

        return $files;
    }, 'subscribers.php', 'at entries[0]: The subscriber class "App\" is not a fully qualified class name.'],
]);

/**
 * An entry with the given keys changed; a key the changes set to null is left out.
 *
 * @param  array<string, mixed>  $entry
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecChanged(array $entry, array $changes): array
{
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($entry[$key]);
        } else {
            $entry[$key] = $value;
        }
    }

    return $entry;
}

/**
 * An entry of subscribers.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecSubscriber(array $changes): array
{
    return codecChanged(['addon' => null, 'class' => 'App\S', 'events' => [['class' => 'App\E', 'name' => 'a.b', 'version' => 1]], 'lane' => 'critical', 'name' => 'a.s', 'package' => 'acme/a', 'projection' => 'fragments'], $changes);
}

/**
 * An entry of hooks.php, of no addon, with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecHook(array $changes): array
{
    return codecChanged(['addon' => null, 'budget_ms' => 1, 'class' => 'App\H', 'command' => 'a.b', 'command_class' => 'App\C', 'command_version' => 1, 'package' => 'acme/a', 'phase' => 'validate', 'priority' => 0, 'reads' => null], $changes);
}

/**
 * An entry of schema.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecSchema(array $changes): array
{
    return codecChanged(['extends' => ['app:note'], 'field_type_contributor' => 'Acme\\Reviews\\ReviewsFieldTypes', 'field_types' => ['reviews:stars'], 'namespace' => 'reviews', 'package' => 'acme/cms-reviews', 'types' => ['reviews:review']], $changes);
}

/**
 * An entry of actions.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecAction(array $changes): array
{
    $entry = ['class' => 'App\A', 'command' => 'a.b', 'command_class' => 'App\C', 'command_version' => 1, 'kind' => 'write', 'package' => 'acme/a', 'surfaces' => ['rest']];

    return array_filter([...$entry, ...$changes], static fn (mixed $value): bool => $value !== null);
}

it('gives every file of a build the same build, and another registry another build', function (): void {
    $files = codecFiles(codecRegistry());
    $empty = codecFiles(CompiledRegistry::empty());

    expect(codecFile($files, 'hooks')['build'])->toBe(codecFile($files, 'commands')['build'])
        ->and(codecFile($files, 'commands')['build'])->not->toBe(codecFile($empty, 'commands')['build']);
});

it('tells files of different builds from files of one build', function (): void {
    $codec = new RegistryCacheCodec;
    $files = codecFiles(codecRegistry());

    expect($codec->fromDifferentBuilds($files))->toBeFalse()
        ->and($codec->fromDifferentBuilds([...$files, 'hooks' => codecFiles(CompiledRegistry::empty())['hooks']]))->toBeTrue()
        ->and($codec->fromDifferentBuilds([...$files, 'hooks' => ['entries' => [], 'format' => 1, 'registry' => 'hooks']]))->toBeFalse()
        ->and($codec->fromDifferentBuilds([...$files, 'hooks' => 'hooks']))->toBeFalse();
});

it('knows how many entries each registry holds', function (): void {
    $registry = codecRegistry();

    expect(array_map($registry->count(...), RegistryName::cases()))->toBe([1, 1, 2, 1, 2]);
});

it('gives the kernel the action of a command by its name and version, and by its class', function (): void {
    $write = codecRegistry()->actions[0];
    $query = new ActionEntry('App\Actions\FindNoteAction', 'acme/notes', ActionKind::Query, new CommandName('note.find'), 2, 'App\Queries\FindNote', []);
    $registry = new CompiledRegistry([], [], [$write, $query]);

    expect($registry->action(new CommandName('note.create'), 1))->toBe($write)
        ->and($registry->action(new CommandName('note.find'), 2))->toBe($query)
        ->and($registry->action(new CommandName('note.find'), 1))->toBeNull()
        ->and($registry->action(new CommandName('note.delete'), 1))->toBeNull()
        ->and($registry->actionFor('App\Commands\CreateNote'))->toBe($write)
        ->and($registry->actionFor('\app\queries\findnote'))->toBe($query)
        ->and($registry->actionFor('App\Commands\DeleteNote'))->toBeNull()
        ->and(CompiledRegistry::empty()->action(new CommandName('note.create'), 1))->toBeNull()
        ->and($query->exposes(Surface::Rest))->toBeFalse()
        ->and($write->exposes(Surface::Mcp))->toBeTrue();
});

it('reads back a subscriber without a projection as null, and one with a projection as its name', function (): void {
    $read = new RegistryCacheCodec()->decode(codecFiles(codecRegistry()), '/cache');

    expect($read->subscribers[0]->projection)->toEqual(new ProjectionName('fragments'))
        ->and($read->subscribers[0]->addon)->toBeNull()
        ->and($read->subscribers[1]->addon)->toEqual(new AddonNamespace('reviews'))
        ->and($read->hooks[0]->addon)->toBeNull()
        ->and($read->hooks[0]->reads)->toBeNull()
        ->and($read->hooks[1]->addon)->toEqual(new AddonNamespace('reviews'))
        ->and($read->hooks[1]->reads)->toBe(ClassificationAccess::Internal)
        ->and($read->schema)->toEqual(codecRegistry()->schema)
        ->and($read->subscribers[1]->projection)->toBeNull()
        ->and($read->subscribers[0]->lane)->toBe(Lane::Critical)
        ->and($read->subscribers[0]->events[1]->type)->toEqual(new EventType('note.created', 1));
});

it('gives another build when only a subscriber changes', function (): void {
    $registry = codecRegistry();
    $moved = new CompiledRegistry($registry->commands, $registry->hooks, $registry->actions, [
        new SubscriberEntry('App\Subscribers\InvalidateNotes', 'acme/notes', new SubscriptionName('notes.fragments'), Lane::Standard, new ProjectionName('fragments'), $registry->subscribers[0]->events),
        $registry->subscribers[1],
    ]);

    expect(codecFile(codecFiles($moved), 'commands')['build'])->not->toBe(codecFile(codecFiles($registry), 'commands')['build']);
});

it('gives another build when only a schema contribution or what the hook of an addon reads changes', function (): void {
    $registry = codecRegistry();
    $contributions = new CompiledRegistry($registry->commands, $registry->hooks, $registry->actions, $registry->subscribers, [
        new SchemaEntry(new AddonNamespace('reviews'), 'acme/cms-reviews', [new ContributedFieldType('reviews:stars')], [new TypeName('reviews:review')], [new TypeName('app:note')], 'Acme\\Reviews\\ReviewsFieldTypes'),
    ]);
    $reads = new CompiledRegistry($registry->commands, [
        $registry->hooks[0],
        new HookEntry('Acme\Reviews\Hooks\RequireStars', 'acme/cms-reviews', new CommandName('note.create'), 1, 'App\Commands\CreateNote', Phase::Validate, 0, 2, new AddonNamespace('reviews'), ClassificationAccess::Confidential),
    ], $registry->actions, $registry->subscribers, $registry->schema);
    $build = codecFile(codecFiles($registry), 'commands')['build'];

    expect(codecFile(codecFiles($contributions), 'commands')['build'])->not->toBe($build)
        ->and(codecFile(codecFiles($reads), 'commands')['build'])->not->toBe($build);
});
