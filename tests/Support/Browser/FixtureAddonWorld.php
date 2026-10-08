<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/**
 * The world of the browser tests of the panel's extension model (tests/Browser/Panel/PanelAddonsTest.php,
 * PanelInpTest.php), seeded into this checkout's test database by the testkit's fixture writers
 * as the owner role: a site with its root, and three members of staff with profiles. The EDITOR
 * holds a role that may create and revise entries, list nodes, actors and roles, assign and list
 * grants, read the fixture addon's query fixtureaddon.articles and run its command
 * fixtureaddon.slug.set, with a ceiling that reaches the profiles, so every contribution of the
 * addon is theirs and the pickers show names; the READER holds a role with entry.revise alone, so
 * the addon's page, its nav entry, its action and its section that reads the query are never
 * theirs; and the COLLEAGUE
 * holds nothing and is who the editor grants the role REPORTER, which may revise entries, in the
 * form of grant.assign. The editor and the reader have local accounts with PASSWORD.
 *
 * It also derives the manifests cms:build refuses from the addon's own (manifestWith()), compiled
 * as cms:build compiles the installation (refusal()), and names the addon's bundle files.
 */
final readonly class FixtureAddonWorld
{
    public const string EDITOR_EMAIL = 'ida.lund@example.com';

    public const string EDITOR_NAME = 'Ida Lund';

    public const string READER_EMAIL = 'ole.frost@example.com';

    public const string READER_NAME = 'Ole Frost';

    public const string COLLEAGUE_NAME = 'Mette Holm';

    public const string COLLEAGUE_EMAIL = 'mette.holm@example.com';

    public const string PASSWORD = 'correct horse battery staple';

    public const string SITE = 'addons';

    public const string EDITOR_ROLE = 'addonseditor';

    public const string READER_ROLE = 'addonsreader';

    public const string REPORTER_ROLE = 'reporter';

    /** The entry module of the addon's bundle, as its manifest names it. */
    public const string BUNDLE_ENTRY = 'panel.js';

    public function __construct(
        public ActorId $editor,
        public ActorId $reader,
        public ActorId $colleague,
        public NodeId $root,
    ) {}

    /**
     * Seeds the world for a test.
     *
     * @param  int  $seed  the seed of the ids, so two tests of one file seed different ones
     */
    public static function seed(Application $app, int $seed = 41): self
    {
        $clock = new FakeClock;
        $ids = new FakeIdGenerator(seed: $seed, clock: $clock);
        $connections = $app->make(ConnectionResolverInterface::class);
        $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
        $access = new PostgresAccessFixtures($app->make(DatabaseManager::class), $clock, $ids);
        $root = new PostgresStructureFixtures($connections, $clock, $ids)->site(self::SITE, [new Locale('da')])->root->id;
        $hasher = $app->make(PasswordHasher::class);
        $store = $app->make(LocalCredentialStore::class);

        // The commits of the panel run through the application's pipeline at the application's
        // clock, so the partitioned tables get partitions there first.
        $app->make(PartitionFixtures::class)->coverClock($app->make(Clock::class), new DateInterval('P2D'));

        $editor = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;
        $reader = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;
        $colleague = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;

        $identity->addProfile($editor, new ActorProfile(new DisplayName(self::EDITOR_NAME), new EmailAddress(self::EDITOR_EMAIL)));
        $identity->addProfile($reader, new ActorProfile(new DisplayName(self::READER_NAME), new EmailAddress(self::READER_EMAIL)));
        $identity->addProfile($colleague, new ActorProfile(new DisplayName(self::COLLEAGUE_NAME), new EmailAddress(self::COLLEAGUE_EMAIL)));

        // The editor's ceiling reaches the profiles, classified personal, so the pickers show
        // names; the addon reads public whatever the viewer may read, so the reads cap still holds.
        $access->grant($editor, $access->role(self::EDITOR_ROLE, ClassificationAccess::Personal, self::names(
            'entry.create', 'entry.revise', 'node.list', 'actor.list', 'role.list', 'grant.assign', 'grant.list', FixtureAddonServiceProvider::ARTICLES_PERMISSION, FixtureAddonServiceProvider::SLUG_SET_PERMISSION,
        )), $root);
        $access->grant($reader, $access->role(self::READER_ROLE, ClassificationAccess::Internal, self::names('entry.revise')), $root);
        $access->role(self::REPORTER_ROLE, ClassificationAccess::Internal, self::names('entry.revise'));

        $store->bind($editor, new LoginIdentifier(self::EDITOR_EMAIL), $hasher->hash(new Password(self::PASSWORD)));
        $store->bind($reader, new LoginIdentifier(self::READER_EMAIL), $hasher->hash(new Password(self::PASSWORD)));

        return new self($editor, $reader, $colleague, $root);
    }

    /**
     * Signs the member of staff in through the login page and lands on the start page.
     */
    public static function signIn(string $email): PendingAwaitablePage
    {
        $page = visit('/cms');

        $page->assertPathIs('/cms/login');
        $page->type('email', $email)
            ->type('password', self::PASSWORD)
            ->click('button[type="submit"]');
        $page->assertPathIs('/cms');

        return $page;
    }

    /**
     * The fixture addon's manifest with other panel contributions, or other accepted experimental
     * points, as an addon's author might declare it.
     *
     * @param  list<PanelContribution>|null  $contributions  null for the addon's own
     * @param  list<string>|null  $acceptsExperimental  null for the addon's own
     */
    public static function manifestWith(Application $app, ?array $contributions = null, ?array $acceptsExperimental = null): AddonManifest
    {
        $own = new FixtureAddonServiceProvider($app)->addonManifest();
        $panel = $own->panel ?? throw new LogicException('The fixture addon contributes to the panel.');

        return new AddonManifest(
            package: $own->package,
            namespace: $own->namespace,
            coreApi: $own->coreApi,
            docs: $own->docs,
            capabilities: $own->capabilities,
            hooks: $own->hooks,
            subscriptions: $own->subscriptions,
            schema: $own->schema,
            panel: new PanelContributions($panel->sdk, $panel->bundle, $acceptsExperimental ?? $panel->acceptsExperimental, $contributions ?? $panel->contributions, $panel->themes, $panel->lang),
        );
    }

    /**
     * The codes of the problems cms:build refuses the installation with when the fixture addon
     * declares the manifest instead of its own, each once, sorted; none when the build passes.
     *
     * @return list<string>
     */
    public static function refusal(Application $app, AddonManifest $manifest): array
    {
        $declared = ProviderAddonManifests::of($app);
        $addons = new DeclaredAddons(
            array_map(static fn (AddonManifest $known): AddonManifest => $known->package === $manifest->package ? $manifest : $known, $declared->manifests),
            $declared->problems,
            $declared->bundles,
            $declared->core,
            $declared->catalogues,
        );

        try {
            $app->make(RegistryCompiler::class)->compile(
                $app->make(DeclarationScanner::class)->scan(ProviderScanRoots::of($app)),
                $addons,
                $app->make(BuildSettings::class),
                $app->make(ContractSchemas::class)->shapes(),
            );
        } catch (RegistryBuildFailed $failed) {
            $codes = array_values(array_unique(array_map(static fn (BuildProblem $problem): string => $problem->code->value, $failed->problems)));
            sort($codes, SORT_STRING);

            return $codes;
        }

        return [];
    }

    /**
     * @return list<CommandName>
     */
    private static function names(string ...$names): array
    {
        return array_values(array_map(static fn (string $name): CommandName => new CommandName($name), $names));
    }
}
