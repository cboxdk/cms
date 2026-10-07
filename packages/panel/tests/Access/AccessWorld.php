<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Access;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Pages\AccessGrantsController;
use Cbox\Cms\Panel\Pages\AccessRolesController;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Registry\InstallationRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roles and grants pages on real Postgres behind the panel itself (PRD 5.10, 13.4): the
 * installation's registry, compiled from the providers' scan roots as cms:build compiles it, in
 * a FakeRegistryCache with a FixtureBuild, the REST routes of the registry registered so the
 * pages' reads can be made over REST too, and a world of access written by the testkit's fixture
 * writers as the owner role: a site with its root and one section; ADMIN, a member of staff with a
 * profile and a role of ceiling personal that may run the access commands and queries and
 * entry.revise on the root, so the pages read everything and the pickers show every profile;
 * OLE, a member of staff with a profile and no grant; the roles EDITOR (entry.revise, internal),
 * which an administrator may give, PUBLISHER (entry.publish, internal), which this administrator
 * may not give because it does not hold entry.publish, and ADMINS (grant.assign and grant.list,
 * personal), administrative, so a grant of it needs step-up; and SERVICE, a service actor granted
 * as ADMIN is, with a service credential, the one kind of viewer that reaches REST in part 1 of
 * B1. SERVICE_PATH serves the pages' controllers to it behind the credential, so a test compares a
 * page's result with REST for one viewer.
 */
final readonly class AccessWorld
{
    public const string ADMIN_EMAIL = 'ada.admin@example.com';

    public const string ADMIN_NAME = 'Ada Admin';

    public const string OLE_EMAIL = 'ole.frost@example.com';

    public const string OLE_NAME = 'Ole Frost';

    public const string ADMIN_ROLE = 'accessadmin';

    public const string EDITOR = 'editor';

    public const string PUBLISHER = 'publisher';

    public const string ADMINS = 'admins';

    /** The access pages behind a Bearer credential, `<SERVICE_PATH>/roles` and `/grants`. */
    public const string SERVICE_PATH = '/x18/access';

    /** The permissions of the administrator's role: the access commands and queries, and editing. */
    public const array ADMINISTRATION = ['role.list', 'role.create', 'role.set_permissions', 'grant.list', 'grant.assign', 'grant.revoke', 'actor.list', 'entry.revise', 'entry.create'];

    public ?FixtureBuild $fixture;

    public CompiledRegistry $registry;

    public NodeId $root;

    public NodeId $section;

    public ActorId $admin;

    public ActorId $ole;

    public ActorId $service;

    public TransportCredential $serviceCredential;

    public RoleId $adminRole;

    public RoleId $editor;

    public RoleId $publisher;

    public RoleId $admins;

    public GrantId $adminGrant;

    /**
     * @param  int  $seed  the seed of the ids the fixtures write
     * @param  bool  $fixtureBuild  whether to bind a FixtureBuild; a browser test runs against the real build
     */
    public function __construct(Application $app, int $seed = 83, bool $fixtureBuild = true, string $site = 'access')
    {
        $this->registry = InstallationRegistry::compile($app);
        $cache = new FakeRegistryCache;
        $cache->write($this->registry);
        $app->instance(RegistryCache::class, $cache);
        $app->instance(CompiledRegistry::class, $this->registry);
        $this->fixture = $fixtureBuild ? FixtureBuild::write() : null;
        $this->fixture?->bind($app);

        RestRoutes::register($app->make(Registrar::class), $this->registry);

        $clock = $app->make(Clock::class);
        $ids = new FakeIdGenerator(seed: $seed, clock: $clock);
        $connections = $app->make(ConnectionResolverInterface::class);
        $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
        $access = new PostgresAccessFixtures($app->make(DatabaseManager::class), $clock, $ids);
        $structure = new PostgresStructureFixtures($connections, $clock, $ids);
        $tree = $structure->site($site, [new Locale('da'), new Locale('en')]);
        $this->root = $tree->root->id;
        $this->section = $structure->node($tree->root)->id;

        $this->admin = $identity->addActor(ActorClass::Staff)->id;
        $identity->addProfile($this->admin, new ActorProfile(new DisplayName(self::ADMIN_NAME), new EmailAddress(self::ADMIN_EMAIL)));
        $this->ole = $identity->addActor(ActorClass::Staff)->id;
        $identity->addProfile($this->ole, new ActorProfile(new DisplayName(self::OLE_NAME), new EmailAddress(self::OLE_EMAIL)));
        $this->service = $identity->addActor(ActorClass::Service)->id;

        $this->adminRole = $access->role(self::ADMIN_ROLE, ClassificationAccess::Personal, $this->names(...self::ADMINISTRATION));
        $this->editor = $access->role(self::EDITOR, ClassificationAccess::Internal, $this->names('entry.revise'));
        $this->publisher = $access->role(self::PUBLISHER, ClassificationAccess::Internal, $this->names('entry.publish'));
        $this->admins = $access->role(self::ADMINS, ClassificationAccess::Personal, $this->names('grant.assign', 'grant.list'));
        $this->adminGrant = $access->grant($this->admin, $this->adminRole, $this->root);
        $access->grant($this->service, $this->adminRole, $this->root);
        $this->serviceCredential = $identity->issue(new ServiceCredentialSpec($this->service, IssuerKind::Service, ClassificationAccess::Sensitive, $clock->now()->modify('+1 day')));

        // The pages' controllers behind a Bearer credential instead of the panel's session, for a
        // viewer who reaches REST too: in part 1 of B1 a person reaches REST with no credential of
        // their own, so the one viewer of both surfaces is a service actor.
        foreach (['roles' => AccessRolesController::class, 'grants' => AccessGrantsController::class] as $path => $controller) {
            $app->make(Registrar::class)->get(self::SERVICE_PATH.'/'.$path, static function (Request $request) use ($app, $controller): Response {
                $credential = RequestCredential::of($request) ?? throw new LogicException('The test route needs a credential.');
                $request->attributes->set(PanelSessions::PRINCIPAL, $app->make(CredentialVerifier::class)->verify($credential));

                return $app->make($controller)($request);
            });
        }
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
