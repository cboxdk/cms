---
title: Add a panel page
weight: 44
description: "Add a page of the panel behind the login: the page's name and route, the query it reads as the person with its schema and codec, the page's props schema and DTO, the React page on the component kit, its navigation entry with the permission it needs, the point it declares, and the tests that hold the page to REST and to the browser."
---

# Add a panel page

A page of the panel behind the login shows a person what a query of the kernel answers them (PRD 13.4): the page reads the query through the query pipeline as the person, from the session credential, and its props carry the result as the query's result codec wrote it, or the problem details of a rejected read. The page renders the [shell's points](../addons/panel-shell.md) around its content and declares the points of its own that addons contribute to, and a nav entry with the permission the viewer must hold puts it in the navigation. The who-am-I page was added this way (B1-T13). [Panel pages](../addons/panel-pages.md) describes the pages; this recipe is the order to build one in.

## Inputs

- **page**: the page's name, a `PageName` such as `account.me`, which the page's points name as their page and its nav entry opens, and its path below the panel's prefix, such as `account/me`.
- **query**: the query the page reads, its name and version, such as `actor.me` version 1, and the result it answers with. A query a page reads is on REST too, so the panel and REST stay in parity.
- **permission**: the command or read a viewer must hold on some node to see the page's nav entry, or none for a page every actor may open.
- **points**: the points the page declares, each with its kind, its region when it is a slot, and its props class, such as `account.me.sections@1` with the viewer's actor id.
- **texts**: the keys of the page's texts in the panel's catalogues, `js/panel/src/i18n/catalogues`, `da.json` and `en.json` alike.

## Files

Paths below are relative to the root of the repository; `<Page>` is the page's name in TitleCase, such as `AccountMe`.

| Path | What it holds |
|---|---|
| `packages/core/src/<Feature>/Domain/Queries/<Query>.php`, `Domain/Dto/<Result>.php`, `Domain/<Reader>.php`, `Adapter/Postgres<Reader>.php` and `Actions/<Query>Action.php` | the query and its result, the port it reads through with its Postgres implementation, and the query action with `#[Action]` on `Surface::Rest` and `Surface::Inertia` (when no query answers what the page shows). A query every actor may run implements `ActorQuery`; the action is bound in `CoreServiceProvider` |
| `packages/core/resources/schemas/queries/<query>.v<n>.json` and `<query>.result.v<n>.json`, and `packages/generators/src/Protocol/Domain/ProtocolSchemas.php` | the query's JSON Schemas and their bindings in `queries()`; `composer generate:protocol` writes the codecs into `packages/core/src/Codecs/Boundary/Generated` and `cms:generate` the TypeScript into `workbench/resources/js/cms/generated/protocol` |
| `packages/panel/src/<Page>/Domain/<Page>.php` | the page's constants: its name, its path, the query it reads and the points it declares |
| `packages/panel/src/<Page>/Domain/Dto/<Point>V1.php` and `packages/panel/resources/schemas/points/<point>.v1.json` | a point the page declares, `#[PanelPoint]` on its props class, and the props' schema, bound in `tools/src/Protocol/Domain/PanelPointSchemas.php`; `composer generate:protocol` writes its codec into `packages/panel/src/Boundary/Generated/Points` and its TypeScript into `js/panel-sdk/src/generated/points` |
| `packages/panel/src/Shell/Domain/OwnPage.php` and `packages/panel/src/Domain/PanelRoute.php` | the page among the panel's own pages, with its route and the query it reads, and the route's name; a page behind the login loads the addons' panel UI (`allowsAddons()`) |
| `packages/panel/src/Domain/Dto/<Page>Page.php` and `packages/panel/resources/schemas/pages/<page>.v1.json` | the page's props, the address of the logout and the read's result and rejection as documents of the kernel's contracts, bound in `tools/src/Protocol/Domain/PanelPageSchemas.php`, whose `PROTOCOL_CODECS` names the result codec the page validates its result with; `composer generate:protocol` writes the page's codec into `packages/panel/src/Boundary/Generated` and the TypeScript into `js/panel/src/generated` |
| `packages/panel/src/Pages/<Page>Controller.php`, `packages/panel/src/Boundary/PanelPages.php` and `packages/panel/src/PanelRoutes.php` | the controller, which resolves the page's contributions, reads the query through `PanelReads` and the `QueryPipeline` and renders the page through `PanelPages`; the page's render method and component name; and the route behind the session middleware |
| `packages/panel/src/Contributions/Domain/CoreContributions.php` | the page's nav entry, a `NavContribution` to `shell.nav@1` in the namespace `cms`, with the permission in its `Scope` |
| `js/panel/src/pages/<Page>.tsx` and `js/panel/src/i18n/catalogues/*.json` | the React page on `@cboxdk/cms-ui-kit` inside `PanelShell`, which validates its result with the generated validator, hosts its points with `PointHost`, and keeps its sign-out; and every text it shows, in both catalogues |
| `packages/core/tests/Actions/<Query>Test.php`, `packages/core/tests/<Feature>/<Reader>Behaviour.php` with a test class per implementation, `tests/Codecs/QueryCodecsTest.php` and `tests/Feature/Surfaces/SurfaceContractTest.php` | the query through the pipeline with fakes, the port's fake held to Postgres, the codecs' fixtures, and the query in the list of exposed reads |
| `packages/panel/tests/Feature/<Page>PageTest.php`, `tests/Codecs/PanelPageValidatorsTest.php`, `packages/panel/tests/Shell/QueryPageParityTest.php` and `tests/Browser/Panel/<Page>Test.php` | the page over HTTP with the read's result and rejection, the page's states against its generated validator, the page's query held to REST, and the page in Chromium with the shared assertions and its screenshot |
| `docs/addons/<pages>.md`, `docs/developers/panel.md`, `tools/src/Docs/Domain/Screenshots.php`, `CLAUDE.md` and `AGENTS.md` | the page and its query with a running example, the page's row in the props table, the screenshot's entry, and the paragraph in "Hvor ting bor", the same in both files |

## Steps

1. Write the query, its result, the port with its Postgres implementation and its fake, and the query action, bound in `CoreServiceProvider`. Write the two schemas, bind them in `ProtocolSchemas::queries()`, and run `composer generate:protocol` and `vendor/bin/testbench cms:generate`.
2. Declare the page: its constants, its case of `OwnPage` with its route and query, the route's name in `PanelRoute`, and the point it declares with its props schema, bound in `PanelPointSchemas`. Run `composer generate:protocol`, `vendor/bin/testbench cms:build` and `vendor/bin/testbench cms:panel:stories`.
3. Write the page's props DTO and schema, bound in `PanelPageSchemas`, with the result codec in `PROTOCOL_CODECS`, and run `composer generate:protocol` again. Write the controller, the render method and the route.
4. Add the nav entry to `CoreContributions::all()` with the permission its viewer must hold, and run `vendor/bin/testbench cms:build` again.
5. Write the React page and its texts in both catalogues, and run `npm run typecheck`, `npm run lint`, `npm run format:check` and `npm run lint:translations`. Build the panel with `composer panel:build` before the browser test.
6. Write the tests, the page, the screenshot's entry and the paragraph; capture the screenshot with `composer image:run -- env CMS_DOCS_SCREENSHOTS=1 vendor/bin/pest --testsuite=Browser tests/Browser/Panel/<Page>Test.php`; and run `composer check` and `composer docs:check`. Record every new and changed test in `CHECKS-LOG.md`.

## Checks

- The query gets its surface contract tests from its `#[Action]` alone (GUARDRAILS 9), one per surface, and fails without its schemas and codecs; `SurfaceContractTest` lists it among the exposed reads.
- `SurfaceParityTest` fails for a page whose query REST does not expose, and `QueryPageParityTest` shows the refusal with a planted registry.
- `PanelPageValidatorsTest` renders the page in each of its states and holds the props to the generated validator and the PHP codec; `tests/Support/Panel/RenderedPanelPoints.php` holds every declared point to a page that renders it.
- `tests/Codecs/PanelPointCodecsTest.php` reads the point's sample props through its codec, and `packages/cli/tests/Console/PanelPointsCommandTest.php` lists the point.
- The browser test logs in, opens the page from the navigation and makes the shared assertions of `tests/Support/Browser/PanelPage.php`: its texts, an empty console, no script error, no axe finding and every WCAG 2.2 AA rule.
- `composer check` and `composer docs:check` pass.

## Running example

The sections point of the who-am-I page:

<!-- example-file: packages/panel/src/Account/Domain/Dto/AccountMeSectionsV1.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Account\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\Account\Domain\AccountMe;

/**
 * The props of account.me.sections@1, the sections of the who-am-I page (PRD 13.4): a slot in the
 * page's sections region, below the page's own profile and grants, where an addon adds a section
 * about the viewer, such as the sessions it has open. Its props are the viewer's actor id, which
 * a section's data query takes as its input; the profile and the grants are the page's own and
 * never handed on.
 */
#[Experimental]
#[PanelPoint(name: AccountMe::SECTIONS, version: 1, kind: PointKind::Slot, page: AccountMe::PAGE, since: '1.0', label: 'panel.points.account_me_sections', region: Region::Sections)]
final readonly class AccountMeSectionsV1
{
    public function __construct(
        public ActorId $actor,
    ) {}
}
```

The panel's own pages, with the query each reads:

<!-- example-file: packages/panel/src/Shell/Domain/OwnPage.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Domain\PanelRoute;

/**
 * The panel's own pages behind the login (PRD 13.4): each by the page id a panel point names as
 * its page and a nav entry opens, with the route that serves it and, for a page that reads, the
 * query its props come from. The pages a contribution may navigate to are these and every addon's
 * page the viewer may open; a nav entry the core or a module contributes to shell.nav@1 names one
 * of these as its page. A page that reads gets its props from the query pipeline with the session
 * credential and the query's result codec, so the same read is on REST too: QueryPageParity holds
 * every such query to a REST route.
 */
#[Internal]
enum OwnPage: string
{
    /** The start page, `<prefix>`. */
    case Home = 'home';

    /** The who-am-I page, `<prefix>/account/me`, which reads actor.me. */
    case AccountMe = AccountMe::PAGE;

    public function name(): PageName
    {
        return new PageName($this->value);
    }

    public function route(): PanelRoute
    {
        return match ($this) {
            self::Home => PanelRoute::Home,
            self::AccountMe => PanelRoute::AccountMe,
        };
    }

    /**
     * The query the page's props come from, or null for a page that reads nothing.
     */
    public function query(): ?CommandRef
    {
        return match ($this) {
            self::Home => null,
            self::AccountMe => AccountMe::query(),
        };
    }

    /**
     * The page with the id, or null when the panel has no own page of that id.
     */
    public static function named(string $page): ?self
    {
        return self::tryFrom($page);
    }
}
```

The controller of the page:

<!-- example-file: packages/panel/src/Pages/AccountMeController.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The who-am-I page of a person who logged in (PRD 5.16, 13.4), at `<prefix>/account/me`: the
 * person's own actor, profile and grants, read with actor.me through the QueryPipeline as the
 * person, from the session credential, with the result codec's document in the page's props, and
 * the contributions active for the person on the points it renders, the sections of the page with
 * the viewer's actor id as their props. It holds no logic of its own.
 */
#[Internal]
final readonly class AccountMeController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private PanelReads $reads,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $viewer = $this->contributions->viewer($request, OwnPage::AccountMe->value);
        $active = $this->resolve->resolve($this->contributions->view($request, OwnPage::AccountMe->value, [new RenderedPoint(AccountMe::sections(), new AccountMeSectionsV1($viewer->actor))]));
        $read = $this->pipeline->run($this->reads->call($request, new WhoAmI));

        return $this->pages->accountMe($request, $this->reads->answer($read, $this->reads->codec(AccountMe::query())), $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
```

The page's read over fakes, which the page tests use:

<!-- example-file: packages/panel/tests/Account/AccountMeWorld.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Account;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Dto\OwnGrant;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeOwnActorReader;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * The who-am-I page's read over fakes (GUARDRAILS 9, PRD 5.16, 13.4): the real QueryPipeline with
 * the action of actor.me over a FakeOwnActorReader that gives one person their self, the
 * credential verifier of the login world the person signed in through, so the session credential
 * the panel carries verifies, a FakeAccessResolver that gives the person public access over the
 * root, and the query authorizer deciding as the kernel's does, from no grant at all, so the page
 * reads only because actor.me is an ActorQuery. refusing() gives a pipeline whose authorizer
 * refuses every read, for the page's rejected state.
 */
final readonly class AccountMeWorld
{
    public const string ROLE = '0192a0c0-0000-7000-8000-000000000c11';

    public const string GRANT = '0192a0c0-0000-7000-8000-000000000c21';

    public const string NODE = '0192a0c0-0000-7000-8000-000000000c31';

    public const string NAME = 'Jonas Berg';

    public const string ROLE_HANDLE = 'desk';

    public function __construct(
        private CredentialVerifier $verifier,
        public ActorId $person,
        public string $email,
    ) {}

    /**
     * The person's own self as actor.me gives it: an active member of staff with a profile and one
     * grant of the role desk on the node in da.
     */
    public function self(): ActorMe
    {
        return new ActorMe(
            $this->person,
            ActorClass::Staff,
            ActorState::Active,
            AggregateVersion::first(),
            new ActorProfile(new DisplayName(self::NAME), new EmailAddress($this->email)),
            [new OwnGrant(GrantId::fromString(self::GRANT), RoleId::fromString(self::ROLE), new RoleHandle(self::ROLE_HANDLE), NodeId::fromString(self::NODE), GrantEffect::Allow, [new Locale('da')], AggregateVersion::first())],
        );
    }

    /**
     * The pipeline that answers actor.me with the person's self.
     */
    public function pipeline(): QueryPipeline
    {
        return $this->build(FakeQueryAuthorizer::granting(new FakePermissions([])));
    }

    /**
     * The pipeline that refuses every read.
     */
    public function refusing(): QueryPipeline
    {
        return $this->build(new FakeQueryAuthorizer('The test refuses every read.'));
    }

    private function build(FakeQueryAuthorizer $authorizer): QueryPipeline
    {
        $clock = new FakeClock;
        $access = new FakeAccessResolver;
        $access->grant($this->person, [new AccessRegion(new NodePath('a1'))], ClassificationAccess::Public);

        return new QueryPipeline(
            new FakeQueryActions([WhoAmI::class => ProbeQueryBinding::of(new WhoAmIAction(new FakeOwnActorReader($this->person)->add($this->self())), 'actor.me', 1)]),
            $this->verifier,
            $access,
            $authorizer,
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(new FakeTypeCatalog),
            new FakeReadAudit,
            new FakeQueryTransaction(sessions: $access),
            new PipelineTelemetry(new FakeTelemetry, $clock, new FakeStopwatch),
        );
    }
}
```

The page over HTTP:

<!-- example: packages/panel/tests/Feature/AccountMePageTest.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Pages\AccountMeController;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Account\AccountMeWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The who-am-I page over HTTP (PRD 5.16, 13.4), in the workbench, which mounts the panel at /cms:
 * a person who signed in gets the page Account/Me with the result of actor.me, read through the
 * QueryPipeline as the person from the session credential, in its props as the result codec wrote
 * it; a rejected read gives the problem details instead; the core's nav entry to the page is among
 * the contributions the person gets and the page is among the pages a contribution may navigate
 * to; the page is never stored; and without a session the panel sends the browser to the login.
 */
final class AccountMePageTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'jonas.berg@example.com';

    private ?AccountMeWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $logins = $this->setUpPanelLogins();
        $person = $logins->person(self::EMAIL);
        $this->world = new AccountMeWorld($logins->verifier(), $person->id, self::EMAIL);
        $registry = ContributionWorld::registry(core: CoreContributions::all());

        app()->instance(CompiledRegistry::class, $registry);
        app()->instance(RegistryCache::class, ContributionWorld::cache($registry));
        app()->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        app()->instance(HeldPermissions::class, new FakeHeldPermissions(new FakePermissions([]), new FakeAccessContexts()->grant($person->id, ClassificationAccess::Public)));
        app()->instance(QueryPipeline::class, $this->world->pipeline());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function it_shows_the_person_their_own_actor_profile_and_grants_as_the_result_codec_writes_them(): void
    {
        $world = $this->world ?? self::fail('No world.');
        $response = $this->visitSignedIn('/cms/account/me');
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::ACCOUNT_ME, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertArrayHasKey('rejection', $props);
        self::assertNull($props['rejection']);
        self::assertSame(
            json_decode(new ActorMeCodecV1()->encode($world->self(), ClassificationAccess::Public), true, 16, JSON_THROW_ON_ERROR),
            $props['result'] ?? null,
        );
        self::assertSame($world->email, $this->at($props, 'result', 'profile', 'email'));
    }

    #[Test]
    public function it_lists_the_core_s_nav_entry_to_the_page_and_the_page_among_the_pages_a_contribution_may_open(): void
    {
        $page = $this->page($this->visitSignedIn('/cms/account/me'));
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $cms = $props[ContributionProps::CMS] ?? null;
        $contributions = json_decode((string) json_encode(is_array($cms) ? $cms[ContributionProps::CONTRIBUTIONS] ?? null : null), true);
        $nav = [];

        foreach (is_array($contributions) && is_array($contributions['points'] ?? null) ? $contributions['points'] : [] as $point) {
            if (is_array($point) && ($point['point'] ?? null) === 'shell.nav@1') {
                $nav = is_array($point['fills'] ?? null) ? $point['fills'] : [];
            }
        }

        self::assertSame([CoreContributions::ACCOUNT_ME_NAV], array_column($nav, 'id'));
        self::assertSame(['icon' => null, 'label' => 'panel.nav.account_me', 'page' => 'account.me'], $this->at($nav, 0, 'nav'));
        self::assertSame('cms', $this->at($nav, 0, 'addon'));
        self::assertContains(['page' => 'account.me', 'url' => '/cms/account/me'], is_array($contributions) && is_array($contributions['pages'] ?? null) ? $contributions['pages'] : []);
    }

    #[Test]
    public function it_shows_the_problem_details_of_a_rejected_read_instead_of_a_result(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->refusing());

        $response = $this->visitSignedIn('/cms/account/me');
        $props = is_array($this->page($response)['props'] ?? null) ? $this->page($response)['props'] : [];

        $response->assertOk();
        self::assertArrayHasKey('result', $props);
        self::assertNull($props['result']);
        self::assertSame('unauthorized', $this->at($props, 'rejection', 'code'));
        self::assertSame(403, $this->at($props, 'rejection', 'status'));
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get('/cms/account/me')->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_at_the_route_of_the_own_page_by_its_controller(): void
    {
        $route = app(Router::class)->getRoutes()->getByName(OwnPage::AccountMe->route()->value);

        self::assertSame('cms/account/me', $route?->uri());
        self::assertSame(AccountMeController::class, $route?->getActionName());
    }

    /**
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path): TestResponse
    {
        $this->visitLogin();
        $session = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');

        return $this->withUnencryptedCookie($this->cookieName(), $session)->get($path);
    }

    /**
     * The Inertia page the response rendered, with its props as the browser receives them: objects
     * as arrays, so a document compares by value.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function page(TestResponse $response): array
    {
        $page = $response->viewData('page');

        if (! is_array($page)) {
            self::fail('The response rendered no Inertia page.');
        }

        $page['props'] = json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        return $page;
    }

    /**
     * The value at the keys of a decoded document, or null where there is none.
     *
     * @param  array<array-key, mixed>  $document
     */
    private function at(array $document, string|int ...$keys): mixed
    {
        $value = $document;

        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
```

The page's query held to REST:

<!-- example: packages/panel/tests/Shell/QueryPageParityTest.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Shell;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\QueryPageParity;

/*
 * The panel's own pages that read stay in parity with REST (decided by Sylvester on 29 September
 * 2026): the who-am-I page reads actor.me, so the registry must expose actor.me on REST, or the
 * page is an Inertia query page without a REST route, which SurfaceParityTest fails on. A planted
 * registry whose actor.me is on Inertia alone, or whose actor.me is missing, is named; one with
 * actor.me on REST and Inertia is not, and the start page reads nothing.
 */

/**
 * @param  list<Surface>  $surfaces
 */
function actorMeEntry(array $surfaces): ActionEntry
{
    return new ActionEntry(WhoAmIAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName(AccountMe::QUERY), AccountMe::QUERY_VERSION, WhoAmI::class, $surfaces);
}

it('names the who-am-I page when its query is planted on Inertia alone or is missing', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Inertia])]), OwnPage::cases()))->toBe(['account.me reads actor.me@1'])
        ->and(QueryPageParity::broken(CompiledRegistry::empty(), OwnPage::cases()))->toBe(['account.me reads actor.me@1']);
});

it('accepts a registry with the query on REST, and the start page reads nothing', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Rest, Surface::Inertia])]), OwnPage::cases()))->toBe([])
        ->and(OwnPage::Home->query())->toBeNull()
        ->and(OwnPage::AccountMe->query()?->toString())->toBe('actor.me@1')
        ->and(OwnPage::named('account.me'))->toBe(OwnPage::AccountMe)
        ->and(OwnPage::named('tally.board'))->toBeNull();
});
```

The nav entry with its permission, the query and the point:

<!-- example: examples/Unit/Panel/PanelPagesTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\WhoAmICodecV1;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1;
use Cbox\Cms\Panel\Boundary\Generated\Points\AccountMeSectionsCodecV1;

// A module registers a page in the panel's navigation with a nav entry to shell.nav@1: the entry
// names one of the panel's own pages and, in its scope, the permission the viewer must hold on
// some node to see it; the server decides per viewer. The who-am-I page reads actor.me, a query
// every actor may run without a permission (ActorQuery), whose result carries the subject's own
// profile whatever the reader's classification access, so a person always sees their own name and
// email. The page's sections point hands an addon's section the viewer's actor id and nothing more.

const EXAMPLE_ME = '{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","class":"staff","grants":[{"effect":"allow","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03",'
    .'"locales":["da"],"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04","role":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a05","role_handle":"desk","version":1}],'
    .'"profile":{"display_name":"Ada Byline","email":"ada@example.com"},"state":"active","version":1}';

it('registers a page in the navigation with the permission its viewer must hold', function (): void {
    $entry = new NavContribution(new ContributionId('cms.grants'), 'shell.nav@1', 'panel.nav.grants', 'access.grants', null, 200, new Scope(requires: new CommandName('grant.list')));

    expect($entry->kind())->toBe(PointKind::Nav)
        ->and($entry->page)->toBe('access.grants')
        ->and($entry->scope->requires?->value)->toBe('grant.list')
        ->and($entry->runsCode())->toBeFalse();
});

it('reads who am I without input, and writes the subject s own profile at every access', function (): void {
    $query = new WhoAmICodecV1()->decode('{}', ClassificationAccess::Public);
    $codec = new ActorMeCodecV1;
    $me = $codec->decode(EXAMPLE_ME, ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(WhoAmI::class)
        ->and($query)->toBeInstanceOf(ActorQuery::class)
        ->and($me)->toBeInstanceOf(ActorMe::class)
        ->and($me->profile?->email->value)->toBe('ada@example.com')
        ->and($codec->encode($me, ClassificationAccess::Public))->toBe(EXAMPLE_ME)
        ->and($codec->encode($me, ClassificationAccess::Sensitive))->toBe(EXAMPLE_ME);
});

it('hands the sections of the who-am-I page the viewer s actor id as their props', function (): void {
    $props = new AccountMeSectionsV1(ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'));

    expect(new AccountMeSectionsCodecV1()->encode($props, ClassificationAccess::Public))->toBe('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"}');
});
```
