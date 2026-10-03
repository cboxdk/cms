<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\ResponseFactory;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ContributionWorld on real Postgres behind a test page (PRD 13.4): the page desk.overview at
 * PATH, the Inertia component Desk, which renders desk.cards with the note it is given, sends the
 * contributions ContributionProps works out for the viewer whose credential the request carries,
 * as a panel page behind the login does once PanelSessions has authenticated it. The registry,
 * the point's codec and the addon's query codecs are put in the container, with a FakeTelemetry.
 *
 * The viewers are service actors with service credentials (ceiling sensitive), granted on a site's
 * root by the testkit's fixture writers as the owner role: AUDITOR through a role (ceiling
 * confidential) that may run tally.audit, tally.notes and tally.heavy; VIEWER through one (ceiling
 * confidential) that may run tally.heavy alone.
 */
final readonly class DeskWorld
{
    public const string PATH = '/x10/desk';

    public const string COMPONENT = 'Desk';

    public const string NOTE = 'Weekly desk';

    public const string MEMO = 'Call the printer';

    public FakeTelemetry $telemetry;

    public TransportCredential $auditor;

    public TransportCredential $viewer;

    /**
     * @param  int  $seed  the seed of the ids the fixtures write, another for each world of one test
     */
    public function __construct(Application $app, ?CompiledRegistry $registry = null, int $seed = 61)
    {
        $registry ??= ContributionWorld::registry();
        $app->instance(CompiledRegistry::class, $registry);
        $app->instance(RegistryCache::class, ContributionWorld::cache($registry));
        $app->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        $app->instance(QueryCodecs::class, new QueryCodecs(...TallyCodecs::all()));
        $app->instance(Telemetry::class, $this->telemetry = new FakeTelemetry);

        $clock = $app->make(Clock::class);
        $ids = new FakeIdGenerator(seed: $seed, clock: $clock);
        $connections = $app->make(ConnectionResolverInterface::class);
        $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
        $access = new PostgresAccessFixtures($app->make(DatabaseManager::class), $clock, $ids);
        $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('desk'.$seed, [new Locale('da')])->root->id;

        $auditor = $identity->addActor(ActorClass::Service)->id;
        $viewer = $identity->addActor(ActorClass::Service)->id;
        $access->grant($auditor, $access->role('auditor'.$seed, ClassificationAccess::Confidential, $this->names(ContributionWorld::AUDIT_PERMISSION, 'tally.notes', 'tally.heavy')), $root);
        $access->grant($viewer, $access->role('reader'.$seed, ClassificationAccess::Confidential, $this->names('tally.heavy')), $root);
        $this->auditor = $identity->issue(new ServiceCredentialSpec($auditor, IssuerKind::Service, ClassificationAccess::Sensitive, $clock->now()->modify('+1 day')));
        $this->viewer = $identity->issue(new ServiceCredentialSpec($viewer, IssuerKind::Service, ClassificationAccess::Sensitive, $clock->now()->modify('+1 day')));

        $app->make(Router::class)->get(self::PATH, static function (Request $request) use ($app): Response {
            $credential = RequestCredential::of($request) ?? throw new LogicException('The test page needs a credential.');
            $request->attributes->set(PanelSessions::PRINCIPAL, $app->make(CredentialVerifier::class)->verify($credential));
            $note = $request->query('note');
            $props = $app->make(ContributionProps::class);
            $data = $app->make(RunContributionData::class);
            $active = $app->make(ResolveContributions::class)->resolve($props->view($request, ContributionWorld::PAGE, [
                new RenderedPoint(new PointName('desk.cards'), new DeskCardsV1(is_string($note) ? $note : self::NOTE, self::MEMO)),
            ]));

            return $app->make(ResponseFactory::class)->render(self::COMPONENT, $props->props($request, $active, $data->run(...), $data->refused(...))->props)->toResponse($request);
        });
    }

    /**
     * The headers of an Inertia visit of the page with the credential, and of a partial reload of
     * the props given, as the host asks for deferred props.
     *
     * @param  list<string>  $only
     * @return array<string, string>
     */
    public static function headers(TransportCredential $credential, array $only = []): array
    {
        return [
            'Authorization' => 'Bearer '.$credential->reveal(),
            'X-Inertia' => 'true',
            ...($only === [] ? [] : ['X-Inertia-Partial-Component' => self::COMPONENT, 'X-Inertia-Partial-Data' => implode(',', $only)]),
        ];
    }

    /**
     * @return list<CommandName>
     */
    private function names(string ...$names): array
    {
        return array_values(array_map(static fn (string $name): CommandName => new CommandName($name), $names));
    }
}
