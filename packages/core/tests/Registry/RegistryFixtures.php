<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\AllowedSubscription;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\PanelThemes\Adapter\FileThemeStylesheets;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeSources;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\FindNoteAction;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\IndexNote;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\InvalidateNoteFragments;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteArchived;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCardV1;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteCreated;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteRenamed;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NoteTitle;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\NotifyNoteWebhooks;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\TrimNoteTitle;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\CreateNoteCodec;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Workbench\FixtureAddon\Articles\Boundary\ArticlesCodecs;
use Workbench\FixtureAddon\Slug\Boundary\SlugCodecs;

/**
 * Scan roots over the fixtures in Fixtures/, and scratch directories for the cache.
 */
final class RegistryFixtures
{
    public const string PACKAGE = 'cboxdk/cms-registry-fixtures';

    /** The package of the fixture addon in Fixtures/Addon. */
    public const string ADDON_PACKAGE = 'acme/cms-reviews';

    /** @var list<string> */
    private static array $scratch = [];

    public static function root(string $fixture, string $package = self::PACKAGE): ScanRoot
    {
        return new ScanRoot($package, __DIR__.'/Fixtures/'.$fixture);
    }

    /**
     * What the scan of the Valid fixture finds through a root of the given package: the command
     * CreateNote, the hook TrimNoteTitle, the query FindNote, the actions CreateNoteAction and
     * FindNoteAction, the subscribers IndexNote, InvalidateNoteFragments and NotifyNoteWebhooks, and
     * the panel point NoteCardV1, in the order the scanner finds them: by file name.
     */
    public static function validDiscovery(string $package = self::PACKAGE): Discovery
    {
        return new Discovery(
            [new CommandEntry(new CommandName('fixture.note.create'), 1, CreateNote::class, $package)],
            [new DiscoveredHook(TrimNoteTitle::class, $package, CreateNote::class, Phase::Transform, 10, 5)],
            [],
            [new QueryEntry(new CommandName('fixture.note.find'), 1, FindNote::class, $package)],
            [
                new DiscoveredAction(CreateNoteAction::class, $package, ActionKind::Write, CreateNote::class, [Surface::Rest, Surface::Mcp]),
                new DiscoveredAction(FindNoteAction::class, $package, ActionKind::Query, FindNote::class, []),
            ],
            self::validSubscribers($package),
            [new PanelPointEntry(
                new PanelPoint('fixture.note.aside', 1, PointKind::Slot, 'fixture.note', '1.0', 'fixture.points.note_aside', Region::Aside),
                NoteCardV1::class,
                $package,
                PointStability::Experimental,
            )],
            self::validPackages($package),
        );
    }

    /**
     * Every class the Valid fixture declares, by its lower-case name, with the package.
     *
     * @return array<string, string>
     */
    public static function validPackages(string $package = self::PACKAGE): array
    {
        $classes = [
            CreateNote::class, CreateNoteAction::class, FindNote::class, FindNoteAction::class, IndexNote::class,
            InvalidateNoteFragments::class, NoteArchived::class, NoteCardV1::class, NoteCreated::class,
            NoteRenamed::class, NoteTitle::class, NotifyNoteWebhooks::class, TrimNoteTitle::class,
        ];
        $packages = array_fill_keys(array_map(strtolower(...), $classes), $package);
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * The subscribers of the Valid fixture as the scanner finds them, by file name.
     *
     * @return list<SubscriberEntry>
     */
    public static function validSubscribers(string $package = self::PACKAGE): array
    {
        $created = new SubscribedEvent(NoteCreated::class, new EventType('fixture.note_created', 1));

        return [
            new SubscriberEntry(IndexNote::class, $package, new SubscriptionName('fixture.search'), Lane::Standard, new ProjectionName('fixture_search'), [$created]),
            new SubscriberEntry(InvalidateNoteFragments::class, $package, new SubscriptionName('fixture.fragments'), Lane::Critical, new ProjectionName('fixture_fragments'), [
                new SubscribedEvent(NoteArchived::class, new EventType('fixture.note_archived', 2)),
                $created,
            ]),
            new SubscriberEntry(NotifyNoteWebhooks::class, $package, new SubscriptionName('fixture.webhooks'), Lane::External, null, [
                $created,
                new SubscribedEvent(NoteRenamed::class, new EventType('fixture.note_renamed', 1)),
            ]),
        ];
    }

    /**
     * The manifest of the fixture addon in Fixtures/Addon: the namespace reviews, which reads up
     * to internal, allows the validate hook on CreateNote and NoteCreated on the standard lane,
     * contributes the field type reviews:stars, owns reviews:review and extends app:note.
     *
     * @param  list<AllowedHook>|null  $hooks  null for the fixture's own
     * @param  list<AllowedSubscription>|null  $subscriptions  null for the fixture's own
     */
    public static function addonManifest(
        string $namespace = 'reviews',
        string $package = self::ADDON_PACKAGE,
        ?array $hooks = null,
        ?array $subscriptions = null,
        CoreApiVersion $coreApi = new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
    ): AddonManifest {
        $addon = new AddonNamespace($namespace);

        return new AddonManifest(
            $package,
            $addon,
            $coreApi,
            __DIR__.'/AddonFiles/docs',
            new AddonCapabilities(ClassificationAccess::Internal),
            $hooks ?? [new AllowedHook(CreateNote::class, Phase::Validate)],
            $subscriptions ?? [new AllowedSubscription(NoteCreated::class, Lane::Standard)],
            new SchemaContributions(
                [new ContributedFieldType($namespace.':stars')],
                [new TypeName($namespace.':review')],
                [new TypeName('app:note')],
                __DIR__.'/AddonFiles/schema',
                AddonFieldTypes::class,
            ),
        );
    }

    public static function builder(string $directory, ContractShapes $shapes = new ContractShapes): BuildRegistry
    {
        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, self::cache($directory), self::documents($directory), new FakeContractSchemas($shapes), new CompilePanelThemes(new FakeThemeSources), new FileThemeStylesheets($directory));
    }

    /**
     * The OpenAPI documents in the directory, with the codecs of every action a build in the
     * testbench application finds on REST: the fixture root Valid's one command,
     * fixture.note.create, the kernel's commands and queries, and the query fixtureaddon.articles
     * and the command fixtureaddon.slug.set of the workbench's fixture addon, which package
     * discovery registers.
     */
    public static function documents(string $directory): FileOpenApiDocuments
    {
        return new FileOpenApiDocuments(
            $directory,
            new CommandCodecs(CreateNoteCodec::commandCodec(), SlugCodecs::setSlug(), ...KernelCommandCodecs::all()),
            new QueryCodecs(ArticlesCodecs::articles(), ...KernelQueryCodecs::all()),
        );
    }

    public static function cache(string $directory): FileRegistryCache
    {
        return new FileRegistryCache($directory, new RegistryCacheCodec);
    }

    /**
     * A directory path that does not exist yet. cleanUp() removes it after the test.
     */
    public static function scratch(): string
    {
        $directory = sys_get_temp_dir().'/cms-registry-'.bin2hex(random_bytes(6));
        self::$scratch[] = $directory;

        return $directory;
    }

    public static function cleanUp(): void
    {
        foreach (self::$scratch as $directory) {
            self::remove($directory);
        }

        self::$scratch = [];
    }

    public static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }

    /**
     * The sha256 of the file of each registry, by file name.
     *
     * @return array<string, string>
     */
    public static function hashes(string $directory): array
    {
        $hashes = [];

        foreach (RegistryName::cases() as $name) {
            $hashes[$name->fileName()] = (string) hash_file('sha256', $directory.'/'.$name->fileName());
        }

        return $hashes;
    }

    /**
     * Every file in the directory, sorted, relative to it.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $files = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * What a cache file returns.
     */
    public static function load(string $path): mixed
    {
        return require $path;
    }
}
