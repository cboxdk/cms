<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTallyCodec;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Pages\AddonPageController;
use Cbox\Cms\Panel\PanelRoutes;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ContributionWorld on real Postgres behind the panel itself (PRD 13.4): the registry, the points'
 * codecs, the addon's query codecs and the tally command's codec are put in the container with a
 * FakeTelemetry and a FixtureBuild, the REST routes of the registry are registered, so the data of
 * the addon's page can be read over REST too, and two members of staff are seeded by the testkit's
 * fixture writers as the owner role: AUDITOR through a role (ceiling confidential) that may run
 * tally.notes, tally.board and tally.add, so the shell's page, nav entry and action are theirs;
 * VIEWER through one that may run tally.heavy alone. A test signs each in through the panel's
 * login form (PanelSignIn). SERVICE is a service actor granted as the auditor is, with a service
 * credential, the one kind of viewer that reaches REST in part 1 of B1; SERVICE_PATH serves the
 * addon pages' controller to it behind the credential, so a test compares a page's data with REST
 * for one viewer.
 */
final readonly class ShellWorld
{
    public const string AUDITOR_EMAIL = 'nina.bruun@example.com';

    public const string VIEWER_EMAIL = 'ole.frost@example.com';

    /** The addon pages behind a Bearer credential, `<SERVICE_PATH>/<namespace>/<path>`. */
    public const string SERVICE_PATH = '/x15/x';

    public FakeTelemetry $telemetry;

    public ?FixtureBuild $fixture;

    public CompiledRegistry $registry;

    public ActorId $auditor;

    public ActorId $viewer;

    /** A service actor granted as the auditor is, whose service credential reaches REST and SERVICE_PATH. */
    public ActorId $service;

    public TransportCredential $serviceCredential;

    /**
     * @param  int  $seed  the seed of the ids the fixtures write, another for each world of one test
     * @param  ActorId|null  $auditor  an actor seeded elsewhere to grant as the auditor, or null to seed one
     * @param  bool  $fixtureBuild  whether to bind a FixtureBuild; a browser test runs against the real build
     */
    public function __construct(Application $app, ?CompiledRegistry $registry = null, int $seed = 71, ?ActorId $auditor = null, bool $fixtureBuild = true)
    {
        $this->registry = $registry ?? ContributionWorld::registry(ContributionWorld::manifest(reads: ClassificationAccess::Confidential));
        $app->instance(CompiledRegistry::class, $this->registry);
        $app->instance(RegistryCache::class, ContributionWorld::cache($this->registry));
        $app->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        $app->instance(QueryCodecs::class, new QueryCodecs(...TallyCodecs::all()));
        $app->instance(CommandCodecs::class, new CommandCodecs(new CommandCodec(new CommandName('tally.add'), 1, new AddTallyCodec, new JsonSchema(AddTallyCodec::SCHEMA))));
        $app->instance(Telemetry::class, $this->telemetry = new FakeTelemetry);
        $this->fixture = $fixtureBuild ? FixtureBuild::write() : null;
        $this->fixture?->bind($app);

        RestRoutes::register($app->make(Registrar::class), $this->registry);

        $clock = $app->make(Clock::class);
        $ids = new FakeIdGenerator(seed: $seed, clock: $clock);
        $connections = $app->make(ConnectionResolverInterface::class);
        $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
        $access = new PostgresAccessFixtures($app->make(DatabaseManager::class), $clock, $ids);
        $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('shell'.$seed, [new Locale('da')])->root->id;

        $this->auditor = $auditor ?? $identity->addActor(ActorClass::Staff)->id;
        $this->viewer = $identity->addActor(ActorClass::Staff)->id;
        $this->service = $identity->addActor(ActorClass::Service)->id;
        $auditors = $access->role('shellauditor'.$seed, ClassificationAccess::Confidential, $this->names('tally.notes', ContributionWorld::BOARD_PERMISSION, ContributionWorld::ADD_PERMISSION));
        $access->grant($this->auditor, $auditors, $root);
        $access->grant($this->service, $auditors, $root);
        $access->grant($this->viewer, $access->role('shellreader'.$seed, ClassificationAccess::Confidential, $this->names('tally.heavy')), $root);
        $this->serviceCredential = $identity->issue(new ServiceCredentialSpec($this->service, IssuerKind::Service, ClassificationAccess::Sensitive, $clock->now()->modify('+1 day')));

        // The addon page's controller behind a Bearer credential instead of the panel's session,
        // for a viewer who reaches REST too: in part 1 of B1 a person reaches REST with no
        // credential of their own, so the one viewer of both surfaces is a service actor.
        $app->make(Registrar::class)->get(self::SERVICE_PATH.'/{namespace}/{path}', static function (Request $request, string $namespace, string $path) use ($app): Response {
            $credential = RequestCredential::of($request) ?? throw new LogicException('The test route needs a credential.');
            $request->attributes->set(PanelSessions::PRINCIPAL, $app->make(CredentialVerifier::class)->verify($credential));

            return $app->make(AddonPageController::class)($request, $namespace, $path);
        })->where(['namespace' => PanelRoutes::NAMESPACE_SEGMENT, 'path' => PanelRoutes::PAGE_PATH]);
    }

    public function cleanUp(): void
    {
        $this->fixture?->remove();
    }

    /**
     * @return list<CommandName>
     */
    private function names(string ...$names): array
    {
        return array_values(array_map(static fn (string $name): CommandName => new CommandName($name), $names));
    }
}
