<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\BundlePath;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonPanel;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelCatalogue;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PointStability;
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
        [new RestRoute(ActionKind::Write, new CommandName('note.create'), 1)],
        codecPanel(),
        [
            new AddonEntry(
                new AddonNamespace('reviews'),
                'acme/cms-reviews',
                new CoreApiVersion(1, 0),
                ClassificationAccess::Internal,
                [new IssuedCommand(new CommandRef(new CommandName('note.create'), 1), 'App\Commands\CreateNote')],
                true,
                new AddonPanel(
                    new PanelApiVersion(1, 0),
                    [PointId::fromString('account.me.sections@1')],
                    new CompiledBundle(new BundlePath('addon.js'), [
                        new BundleFile(new BundlePath('addon.js'), BundleIntegrity::of('export {};'), BundleFileKind::Script),
                        new BundleFile(new BundlePath('addon.css'), BundleIntegrity::of('@layer cms.addon {}'), BundleFileKind::Style),
                    ]),
                    [
                        new PanelCatalogue(PanelLocale::Danish, ['reviews.badge.title' => 'Mærke']),
                        new PanelCatalogue(PanelLocale::English, ['reviews.badge.title' => 'Badge']),
                    ],
                ),
            ),
            new AddonEntry(new AddonNamespace('stamps'), 'acme/cms-stamps', new CoreApiVersion(1, 0), ClassificationAccess::Public, [], false),
        ],
    );
}

/**
 * The panel points of codecRegistry(): a slot in the sections of account.me with two fills, the
 * later one first by priority, and a replacement of command.form keyed by field type.
 *
 * @return list<PanelPointEntry>
 */
function codecPanel(): array
{
    return [
        new PanelPointEntry(
            new PanelPoint('account.me.sections', 1, PointKind::Slot, 'account.me', '1.0', 'panel.points.account_me_sections', Region::Sections),
            'App\Panel\AccountMeSectionsV1',
            'acme/notes',
            PointStability::Experimental,
            [
                new PanelFill(new SlotFill(new ContributionId('reviews.badge'), 'account.me.sections@1', priority: 1000, scope: new Scope([new PageName('account.me')], [new CommandRef(new CommandName('note.create'), 1)], [new TypeName('app:note')], ['reviews:stars', 'text'], new CommandName('note.find'))), 'acme/cms-reviews', 1000),
                new PanelFill(new SlotFill(new ContributionId('cms.profile'), 'account.me.sections@1', priority: 100, scope: Scope::everywhere()), 'cboxdk/cms', 100),
            ],
        ),
        new PanelPointEntry(
            new PanelPoint('command.form.field', 1, PointKind::Replacement, 'command.form', '1.0', 'panel.points.command_form_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::FieldType),
            'App\Panel\FieldInputPropsV1',
            'acme/notes',
            PointStability::Internal,
        ),
    ];
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

it('writes the exact bytes of format 11', function (): void {
    $files = new RegistryCacheCodec()->encode(codecRegistry());
    $header = "<?php\n\ndeclare(strict_types=1);\n\n// Written by php artisan cms:build from the declared scan roots and addon manifests (PRD 13.2).\n// Do not edit and do not commit; run cms:build again instead.\n\n";

    expect(array_keys($files))->toBe(['actions', 'addons', 'commands', 'hooks', 'panel', 'rest', 'schema', 'subscribers'])
        ->and($files['actions'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
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
                'format' => 11,
                'registry' => 'actions',
            ];

            PHP)
        ->and($files['addons'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
                'entries' => [
                    [
                        'core_api' => '1.0',
                        'issues' => [
                            [
                                'class' => 'App\\Commands\\CreateNote',
                                'command' => 'note.create@1',
                            ],
                        ],
                        'namespace' => 'reviews',
                        'package' => 'acme/cms-reviews',
                        'panel' => [
                            'accepts_experimental' => [
                                'account.me.sections@1',
                            ],
                            'bundle' => [
                                'entry' => 'addon.js',
                                'files' => [
                                    [
                                        'integrity' => 'sha384-J56zY7cPQ+lSHgc8L4S3YZ2VSOUP7aglbnAujAeSPnYlUlOGZ93OFZQd3OxUo0Ma',
                                        'kind' => 'style',
                                        'path' => 'addon.css',
                                    ],
                                    [
                                        'integrity' => 'sha384-OURA3k5hJ74BsGlX4ksLSFQSEcbEi1C7beG3lEqFBTVv+fXU25n0GbzRVMFK1W0P',
                                        'kind' => 'script',
                                        'path' => 'addon.js',
                                    ],
                                ],
                            ],
                            'catalogues' => [
                                [
                                    'locale' => 'da',
                                    'texts' => [
                                        'reviews.badge.title' => 'Mærke',
                                    ],
                                ],
                                [
                                    'locale' => 'en',
                                    'texts' => [
                                        'reviews.badge.title' => 'Badge',
                                    ],
                                ],
                            ],
                            'sdk' => '1.0',
                        ],
                        'reads' => 'internal',
                        'ui_theme' => true,
                    ],
                    [
                        'core_api' => '1.0',
                        'issues' => [],
                        'namespace' => 'stamps',
                        'package' => 'acme/cms-stamps',
                        'panel' => null,
                        'reads' => 'public',
                        'ui_theme' => false,
                    ],
                ],
                'format' => 11,
                'registry' => 'addons',
            ];

            PHP)
        ->and($files['commands'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
                'entries' => [
                    [
                        'class' => 'App\\Commands\\CreateNote',
                        'name' => 'note.create',
                        'package' => 'acme/notes',
                        'version' => 1,
                    ],
                ],
                'format' => 11,
                'registry' => 'commands',
            ];

            PHP)
        ->and($files['hooks'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
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
                'format' => 11,
                'registry' => 'hooks',
            ];

            PHP)
        ->and($files['panel'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
                'entries' => [
                    [
                        'class' => 'App\\Panel\\AccountMeSectionsV1',
                        'deprecated' => null,
                        'fills' => [
                            [
                                'command' => null,
                                'contribution' => 'cms.profile',
                                'declaration' => [
                                    'command' => null,
                                    'confirm' => null,
                                    'data' => null,
                                    'icon' => null,
                                    'key' => null,
                                    'kind' => 'slot',
                                    'label' => null,
                                    'message' => null,
                                    'mirrors' => null,
                                    'page' => null,
                                    'patches' => null,
                                    'path' => null,
                                    'point' => 'account.me.sections@1',
                                    'position' => null,
                                    'prefill' => null,
                                    'priority' => 100,
                                    'severity' => null,
                                    'tightens' => null,
                                    'timeout_seconds' => null,
                                    'tone' => null,
                                ],
                                'enabled' => true,
                                'enabling' => 'addon',
                                'ordering' => 'addon',
                                'package' => 'cboxdk/cms',
                                'priority' => 100,
                                'query' => null,
                                'scope' => [
                                    'commands' => [],
                                    'field_types' => [],
                                    'pages' => [],
                                    'requires' => null,
                                    'types' => [],
                                ],
                            ],
                            [
                                'command' => null,
                                'contribution' => 'reviews.badge',
                                'declaration' => [
                                    'command' => null,
                                    'confirm' => null,
                                    'data' => null,
                                    'icon' => null,
                                    'key' => null,
                                    'kind' => 'slot',
                                    'label' => null,
                                    'message' => null,
                                    'mirrors' => null,
                                    'page' => null,
                                    'patches' => null,
                                    'path' => null,
                                    'point' => 'account.me.sections@1',
                                    'position' => null,
                                    'prefill' => null,
                                    'priority' => 1000,
                                    'severity' => null,
                                    'tightens' => null,
                                    'timeout_seconds' => null,
                                    'tone' => null,
                                ],
                                'enabled' => true,
                                'enabling' => 'addon',
                                'ordering' => 'addon',
                                'package' => 'acme/cms-reviews',
                                'priority' => 1000,
                                'query' => null,
                                'scope' => [
                                    'commands' => [
                                        'note.create@1',
                                    ],
                                    'field_types' => [
                                        'reviews:stars',
                                        'text',
                                    ],
                                    'pages' => [
                                        'account.me',
                                    ],
                                    'requires' => 'note.find',
                                    'types' => [
                                        'app:note',
                                    ],
                                ],
                            ],
                        ],
                        'id' => 'account.me.sections@1',
                        'keyed_by' => null,
                        'kind' => 'slot',
                        'label' => 'panel.points.account_me_sections',
                        'max' => null,
                        'multiplicity' => 'many',
                        'ownership' => null,
                        'package' => 'acme/notes',
                        'page' => 'account.me',
                        'region' => 'sections',
                        'since' => '1.0',
                        'stability' => 'experimental',
                        'tightens' => [],
                    ],
                    [
                        'class' => 'App\\Panel\\FieldInputPropsV1',
                        'deprecated' => null,
                        'fills' => [],
                        'id' => 'command.form.field@1',
                        'keyed_by' => 'field_type',
                        'kind' => 'replacement',
                        'label' => 'panel.points.command_form_field',
                        'max' => null,
                        'multiplicity' => 'exclusive',
                        'ownership' => 'own',
                        'package' => 'acme/notes',
                        'page' => 'command.form',
                        'region' => null,
                        'since' => '1.0',
                        'stability' => 'internal',
                        'tightens' => [],
                    ],
                ],
                'format' => 11,
                'registry' => 'panel',
            ];

            PHP)
        ->and($files['rest'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
                'entries' => [
                    [
                        'kind' => 'write',
                        'method' => 'POST',
                        'name' => 'note.create',
                        'path' => '/v1/commands/note.create/v1',
                        'version' => 1,
                    ],
                ],
                'format' => 11,
                'registry' => 'rest',
            ];

            PHP)
        ->and($files['schema'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
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
                'format' => 11,
                'registry' => 'schema',
            ];

            PHP)
        ->and($files['subscribers'])->toBe($header.<<<'PHP'
            return [
                'build' => 'd4af2bb759d613ca7c9605c1216825dbf50d71852238799bcc0e8cb5d3d30ee9',
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
                'format' => 11,
                'registry' => 'subscribers',
            ];

            PHP)
        ->and(new RegistryCacheCodec()->encode(CompiledRegistry::empty())['commands'])->toBe($header."return [\n    'build' => '".hash('sha256', "actions => [];\naddons => [];\ncommands => [];\nhooks => [];\npanel => [];\nrest => [];\nschema => [];\nsubscribers => [];\n")."',\n    'entries' => [],\n    'format' => 11,\n    'registry' => 'commands',\n];\n");
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
    }, 'commands.php', 'at format: format 1 is not format 11, which this version of the core reads'],
    'a file of format 2, whose actions had no command' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'format' => 2];

        return $files;
    }, 'actions.php', 'at format: format 2 is not format 11, which this version of the core reads'],
    'a file of format 3, whose cache had no actions.php' => [static function (array $files): array {
        $files['commands'] = [...codecFile($files, 'commands'), 'format' => 3];

        return $files;
    }, 'commands.php', 'at format: format 3 is not format 11, which this version of the core reads'],
    'a file of format 4, whose cache had no subscribers.php' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'format' => 4];

        return $files;
    }, 'hooks.php', 'at format: format 4 is not format 11, which this version of the core reads'],
    'a file of format 5, whose cache had no schema.php' => [static function (array $files): array {
        $files['subscribers'] = [...codecFile($files, 'subscribers'), 'format' => 5];

        return $files;
    }, 'subscribers.php', 'at format: format 5 is not format 11, which this version of the core reads'],
    'a file of format 6, whose schema.php named no field type contributor' => [static function (array $files): array {
        $files['schema'] = [...codecFile($files, 'schema'), 'format' => 6];

        return $files;
    }, 'schema.php', 'at format: format 6 is not format 11, which this version of the core reads'],
    'a file of format 7, whose cache had no rest.php' => [static function (array $files): array {
        $files['actions'] = [...codecFile($files, 'actions'), 'format' => 7];

        return $files;
    }, 'actions.php', 'at format: format 7 is not format 11, which this version of the core reads'],
    'a file of format 8, whose cache had no panel.php' => [static function (array $files): array {
        $files['hooks'] = [...codecFile($files, 'hooks'), 'format' => 8];

        return $files;
    }, 'hooks.php', 'at format: format 8 is not format 11, which this version of the core reads'],
    'a file of format 9, whose cache had no addons.php' => [static function (array $files): array {
        unset($files['addons']);
        $files['actions'] = [...codecFile($files, 'actions'), 'format' => 9];

        return $files;
    }, 'actions.php', 'at format: format 9 is not format 11, which this version of the core reads'],
    'a missing addons.php' => [static function (array $files): array {
        unset($files['addons']);

        return $files;
    }, 'addons.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'a missing panel.php' => [static function (array $files): array {
        unset($files['panel']);

        return $files;
    }, 'panel.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'a panel point missing a key' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['tightens' => null])]];

        return $files;
    }, 'panel.php', 'at entries[0]: expected the keys class, deprecated, fills, id, keyed_by, kind, label, max, multiplicity, ownership, package, page, region, since, stability, tightens, got class, deprecated, fills, id, keyed_by, kind, label, max, multiplicity, ownership, package, page, region, since, stability'],
    'a panel point id without a version' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['id' => 'account.me.sections'])]];

        return $files;
    }, 'panel.php', 'at entries[0].id: "account.me.sections" is not a panel point id'],
    'an unknown panel point kind' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['kind' => 'widget'])]];

        return $files;
    }, 'panel.php', 'at entries[0].kind: "widget" is not a panel point kind'],
    'an unknown region' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['region' => 'footer'])]];

        return $files;
    }, 'panel.php', 'at entries[0].region: "footer" is not a region'],
    'a slot without a region' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [[...codecPoint([]), 'region' => null]]];

        return $files;
    }, 'panel.php', 'at entries[0]: The slot account.me.sections@1 has no region'],
    'an unknown stability' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['stability' => 'beta'])]];

        return $files;
    }, 'panel.php', 'at entries[0].stability: "beta" is not a stability'],
    'a tightening prop on a slot' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['tightens' => ['description']])]];

        return $files;
    }, 'panel.php', 'at entries[0]: The panel point account.me.sections@1 is of kind slot and lists props a decorator may tighten'],
    'an unknown tightening prop' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['tightens' => ['hidden']])]];

        return $files;
    }, 'panel.php', 'at entries[0].tightens[0]: "hidden" is not a tightening prop'],
    'a panel point of a package that is not one' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['package' => 'notes'])]];

        return $files;
    }, 'panel.php', 'at entries[0]: The package "notes" is not a Composer package name.'],
    'a fill whose contribution is not an id' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['contribution' => 'Reviews'])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].contribution: The contribution id "Reviews" is not'],
    'a fill listed twice' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill([]), codecFill(['priority' => 5])]])]];

        return $files;
    }, 'panel.php', 'at entries[0]: The panel point account.me.sections@1 lists the contribution reviews.badge twice.'],
    'a fill whose scope names a page twice' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['scope' => codecScope(['pages' => ['shell', 'shell']])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].scope: The scope names the page "shell" twice'],
    'a fill whose scope names a command without a version' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['scope' => codecScope(['commands' => ['note.create']])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].scope.commands[0]: "note.create" is not a command and version'],
    'a fill whose scope requires a name that is not a command\'s' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['scope' => codecScope(['requires' => 'Notes'])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].scope.requires: A command name is dot-separated snake_case segments'],
    'a fill whose scope is missing a key' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['scope' => codecScope(['types' => null])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].scope: expected the keys commands, field_types, pages, requires, types, got commands, field_types, pages, requires'],
    'a fill of a kind no contribution has' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['declaration' => codecDeclaration(['kind' => 'theme'])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].declaration.kind: a theme is no contribution to a point'],
    'a declaration missing a key' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['declaration' => codecDeclaration(['tone' => null])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].declaration: expected the keys command, confirm, data,'],
    'an action whose prefill is a list' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['declaration' => codecDeclaration(['kind' => 'action', 'command' => 'App\\R', 'label' => 'a.b', 'confirm' => 'none', 'tone' => 'neutral', 'prefill' => ['/note']])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].declaration.prefill: expected a map of strings, got array'],
    'a fill whose enabled state is not a boolean' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['enabled' => 'yes'])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].enabled: expected a boolean, got string'],
    'a fill ordered by the activation state' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['ordering' => 'activation'])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0]: The order of the contribution reviews.badge comes from the addon or the installation'],
    'a fill of an unknown source' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['enabling' => 'operator'])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].enabling: "operator" is not a fill source'],
    'a step of 40 seconds' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['fills' => [codecFill(['declaration' => codecDeclaration(['kind' => 'flow_step', 'command' => 'notes.draft@1', 'position' => 'before_submit', 'patches' => [], 'timeout_seconds' => 40])])]])]];

        return $files;
    }, 'panel.php', 'at entries[0].fills[0].declaration: The flow step reviews.badge has a timeout of 40 seconds'],
    'a deprecation removed before it began' => [static function (array $files): array {
        $files['panel'] = [...codecFile($files, 'panel'), 'entries' => [codecPoint(['deprecated' => ['remove_in' => '1.0', 'replacement' => null, 'since' => '1.1']])]];

        return $files;
    }, 'panel.php', 'at entries[0].deprecated: The deprecation is removed in 1.0, which is not after 1.1'],
    'an addon missing a key' => [static function (array $files): array {
        $files['addons'] = [...codecFile($files, 'addons'), 'entries' => [codecAddon(['ui_theme' => null])]];

        return $files;
    }, 'addons.php', 'at entries[0]: expected the keys core_api, issues, namespace, package, panel, reads, ui_theme, got core_api, issues, namespace, package, panel, reads'],
    'an addon that reads no classification' => [static function (array $files): array {
        $files['addons'] = [...codecFile($files, 'addons'), 'entries' => [codecAddon(['reads' => 'secret'])]];

        return $files;
    }, 'addons.php', 'at entries[0].reads: "secret" is not a classification'],
    'an addon of a core API that is no version' => [static function (array $files): array {
        $files['addons'] = [...codecFile($files, 'addons'), 'entries' => [codecAddon(['core_api' => '^1.0'])]];

        return $files;
    }, 'addons.php', 'at entries[0].core_api: "^1.0" is not a version as <major>.<minor>'],
    'an addon that issues a command without a version' => [static function (array $files): array {
        $files['addons'] = [...codecFile($files, 'addons'), 'entries' => [codecAddon(['issues' => [['class' => 'App\\R', 'command' => 'note.create']]])]];

        return $files;
    }, 'addons.php', 'at entries[0].issues[0].command: "note.create" is not a command and version'],
    'a bundle file whose path climbs out of the bundle' => [static function (array $files): array {
        $files['addons'] = [...codecFile($files, 'addons'), 'entries' => [codecAddon(['panel' => ['accepts_experimental' => [], 'bundle' => ['entry' => 'addon.js', 'files' => [['integrity' => BundleIntegrity::of('')->value, 'kind' => 'script', 'path' => '../addon.js']]], 'catalogues' => [], 'sdk' => '1.0']])]];

        return $files;
    }, 'addons.php', 'at entries[0].panel.bundle.files[0]: "../addon.js" is not the path of a file in a panel bundle'],
    'a missing rest.php' => [static function (array $files): array {
        unset($files['rest']);

        return $files;
    }, 'rest.php', 'expected an array with the keys build, entries, format, registry, got null'],
    'a REST route missing a key' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['method' => null])]];

        return $files;
    }, 'rest.php', 'at entries[0]: expected the keys kind, method, name, path, version, got kind, name, path, version'],
    'a REST route of an unknown kind' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['kind' => 'delete'])]];

        return $files;
    }, 'rest.php', 'at entries[0].kind: "delete" is not an action kind'],
    'a REST route of version 0' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['version' => 0])]];

        return $files;
    }, 'rest.php', 'at entries[0]: The REST route of "note.create" has version 0. Versions start at 1.'],
    'a REST route whose method is not its kind\'s' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['method' => 'GET'])]];

        return $files;
    }, 'rest.php', 'at entries[0].method: "GET" is not the method of the route of note.create version 1, "POST"'],
    'a REST route whose path is not its own' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['path' => '/v1/note.create'])]];

        return $files;
    }, 'rest.php', 'at entries[0].path: "/v1/note.create" is not the path of the route of note.create version 1, "/v1/commands/note.create/v1"'],
    'a REST route with a name that is not a command name' => [static function (array $files): array {
        $files['rest'] = [...codecFile($files, 'rest'), 'entries' => [codecRoute(['name' => 'Note'])]];

        return $files;
    }, 'rest.php', 'at entries[0].name: A command name is dot-separated snake_case segments'],
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
    }, 'addons.php', 'at build: it comes from another cms:build than actions.php'],
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
 * An entry of rest.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecRoute(array $changes): array
{
    return codecChanged(['kind' => 'write', 'method' => 'POST', 'name' => 'note.create', 'path' => '/v1/commands/note.create/v1', 'version' => 1], $changes);
}

/**
 * An entry of panel.php, the slot account.me.sections@1 without fills, with the given keys
 * changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecPoint(array $changes): array
{
    return codecChanged([
        'class' => 'App\Panel\AccountMeSectionsV1',
        'deprecated' => null,
        'fills' => [],
        'id' => 'account.me.sections@1',
        'keyed_by' => null,
        'kind' => 'slot',
        'label' => 'panel.points.account_me_sections',
        'max' => null,
        'multiplicity' => 'many',
        'ownership' => null,
        'package' => 'acme/notes',
        'page' => 'account.me',
        'region' => 'sections',
        'since' => '1.0',
        'stability' => 'experimental',
        'tightens' => [],
    ], $changes);
}

/**
 * A fill of panel.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecFill(array $changes): array
{
    return codecChanged([
        'command' => null,
        'contribution' => 'reviews.badge',
        'declaration' => codecDeclaration([]),
        'enabled' => true,
        'enabling' => 'addon',
        'ordering' => 'addon',
        'package' => 'acme/cms-reviews',
        'priority' => 1000,
        'query' => null,
        'scope' => codecScope([]),
    ], $changes);
}

/**
 * The declaration of a fill of panel.php, a slot fill of account.me.sections@1, with the given
 * keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecDeclaration(array $changes): array
{
    $keys = ['command', 'confirm', 'data', 'icon', 'key', 'label', 'message', 'mirrors', 'page', 'patches', 'path', 'position', 'prefill', 'severity', 'tightens', 'timeout_seconds', 'tone'];

    return codecChanged([...array_fill_keys($keys, null), 'kind' => 'slot', 'point' => 'account.me.sections@1', 'priority' => 1000], $changes);
}

/**
 * The scope of a fill of panel.php with the given keys changed; a key set to null is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecScope(array $changes): array
{
    $scope = ['commands' => [], 'field_types' => [], 'pages' => [], 'requires' => null, 'types' => []];

    foreach ($changes as $key => $value) {
        if ($value === null && $key !== 'requires') {
            unset($scope[$key]);
        } else {
            $scope[$key] = $value;
        }
    }

    return $scope;
}

/**
 * An entry of addons.php, of an addon without UI, with the given keys changed; a key set to null
 * is left out.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function codecAddon(array $changes): array
{
    return codecChanged(['core_api' => '1.0', 'issues' => [], 'namespace' => 'stamps', 'package' => 'acme/cms-stamps', 'panel' => null, 'reads' => 'public', 'ui_theme' => false], $changes);
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

    expect(array_map($registry->count(...), RegistryName::cases()))->toBe([1, 2, 1, 2, 2, 1, 1, 2]);
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
        ->and($read->panel)->toEqual(codecPanel())
        ->and(array_map(static fn (PanelFill $fill): string => $fill->contribution->value, $read->panel[0]->fills))->toBe(['cms.profile', 'reviews.badge'])
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

it('reads back a fill of every kind of contribution and every addon, with what the build compiled', function (): void {
    $built = PanelBuildWorld::build(
        PanelBuildWorld::addons([PanelBuildWorld::manifest(PanelBuildWorld::everyKind())]),
        PanelBuildWorld::settings([new ContributionOverride(PointId::fromString('notes.form.checks@1'), new ContributionId('approvals.hint'), 5, false)]),
    );
    $read = new RegistryCacheCodec()->decode(codecFiles($built), '/cache');

    expect($read->panel)->toEqual($built->panel)
        ->and($read->addons)->toEqual($built->addons)
        ->and($read->warnings)->toBe([])
        ->and($built->warnings)->not->toBe([]);
});
