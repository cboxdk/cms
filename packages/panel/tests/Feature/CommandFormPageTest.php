<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeCodec;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Pages\CommandFormController;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generic command form over HTTP (PRD 6.1, 13.4), in the workbench, which mounts the panel at
 * /cms: a person who signed in gets, at GET /cms/commands/{command}/v{version}, the address the
 * Inertia profile runs the command at, the page Command with the command's name and version and
 * its JSON Schema as the command's codec carries it, so the form is rendered from the schema and
 * posts to the same address; the page declares the aside point with the command, its version and
 * the schema's title as its props and names the command as its view's subject. A command the
 * profile does not expose, another version of one or one without a codec is the page for a path
 * the panel does not have, with 404; the page is never stored; and a browser without a session is
 * sent to the login. The registry exposes the test-only probe.rename version 1 on REST and Inertia,
 * over the ExposedWorld's fakes.
 */
final class CommandFormPageTest extends TestCase
{
    private const string ROUTE = '/cms/commands/probe.rename/v1';

    private ?FixtureBuild $fixture = null;

    private ?ExposedWorld $world = null;

    private ?Actor $person = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app ?? app();
        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($app);
        $this->world = new ExposedWorld;
        $this->person = $this->world->world->identity->addActor(ActorClass::Staff);
        $this->world->contexts->grant($this->person->id, ClassificationAccess::Internal);

        $app->instance(InertiaActions::class, new InertiaActions(new CompiledRegistry(
            [
                new CommandEntry(new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, 'acme/probe'),
                new CommandEntry(new CommandName('probe.silent'), 1, RenameProbe::class, 'acme/probe'),
            ],
            [],
            [
                new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, [Surface::Rest, Surface::Inertia]),
                new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName('probe.silent'), 1, RenameProbe::class, [Surface::Rest, Surface::Inertia]),
            ],
        )));
        $app->instance(CommandCodecs::class, ExposedWorld::codecs());
        $app->instance(RunExposedCommand::class, $this->world->action());
        $app->instance(CredentialVerifier::class, $this->world->world->identity);
        $app->instance(HeldPermissions::class, new FakeHeldPermissions);
        // The command palette every page behind the login reads action.list for, over fakes.
        $app->instance(QueryPipeline::class, new ActionListWorld(CompiledRegistry::empty(), $this->world->world->identity, $this->world->world->clock, $this->world->world->identity)->pipeline());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture?->remove();
        $this->fixture = null;
        $this->world = null;
        $this->person = null;

        parent::tearDown();
    }

    #[Test]
    public function it_renders_the_form_page_with_the_command_its_version_and_its_schema(): void
    {
        $this->signIn();
        $response = $this->get(self::ROUTE);
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::COMMAND_FORM, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertSame(ExposedWorld::COMMAND, $props['command'] ?? null);
        self::assertSame(1, $props['version'] ?? null);
        self::assertSame(json_decode(RenameProbeCodec::SCHEMA, true, 32, JSON_THROW_ON_ERROR), $props['schema'] ?? null);
    }

    #[Test]
    public function it_declares_the_aside_point_with_what_the_form_is_about_and_lists_the_commands_address(): void
    {
        $this->signIn();
        $page = $this->page($this->get(self::ROUTE));
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $cms = $props[ContributionProps::CMS] ?? null;
        $contributions = is_array($cms) ? $cms[ContributionProps::CONTRIBUTIONS] ?? null : null;

        self::assertIsArray($contributions);
        self::assertSame('/cms/commands', $contributions['commands'] ?? null);
        // No contribution to the aside is compiled, so the point is not among the page's points.
        self::assertNotContains(CommandForm::ASIDE.'@1', array_column(is_array($contributions['points'] ?? null) ? $contributions['points'] : [], 'point'));
    }

    #[Test]
    public function it_answers_the_page_for_a_path_the_panel_does_not_have_for_a_command_the_profile_does_not_expose(): void
    {
        $this->signIn();

        foreach (['/cms/commands/probe.other/v1', '/cms/commands/probe.rename/v2', '/cms/commands/probe.silent/v1'] as $route) {
            $response = $this->get($route);

            $response->assertNotFound();
            self::assertSame(PanelPages::NOT_FOUND, $this->page($response)['component'] ?? null, $route);
        }
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get(self::ROUTE)->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_at_the_commands_address_by_its_controller_and_loads_the_addons_ui(): void
    {
        $route = app(Router::class)->getRoutes()->getByName(PanelRoute::CommandForm->value);

        self::assertInstanceOf(Route::class, $route);
        self::assertSame('cms/commands/{command}/v{version}', $route->uri());
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame(CommandFormController::class, $route->getActionName());
        self::assertTrue(PanelRoute::CommandForm->allowsAddons());
        self::assertSame(self::ROUTE, route(PanelRoute::CommandForm->value, ['command' => 'probe.rename', 'version' => 1], false));
    }

    /**
     * Starts a session of the person and visits the panel's start with it, which binds Laravel's
     * session to it.
     */
    private function signIn(): void
    {
        $world = $this->world ?? self::fail('The test has no world.');
        $person = $this->person ?? self::fail('The test has no person.');
        $session = $world->world->identity->startSession($person->id);
        $this->withUnencryptedCookie(app(SessionCookie::class)->name, $session->reveal());
        $this->get('/cms')->assertOk();
    }

    /**
     * The Inertia page the response rendered, with its props as the browser receives them.
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
}
