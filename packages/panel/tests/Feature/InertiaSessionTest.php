<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Inertia profile below the panel, as the person who logged in (PRD 5.16, GUARDRAILS 2.1, 6):
 * POST /cms/commands/{command}/v{version} takes the session cookie as the credential, behind the
 * panel's session middleware and its CSRF protection. A post with the session cookie and without
 * the CSRF token is refused with 419 and runs nothing; with the token, the command runs as the
 * person, an actor principal whose envelope has the issuer kind human and the surface inertia. A
 * post without a session goes to the login page and runs nothing.
 *
 * The registry exposes the test-only probe.rename version 1 on REST and Inertia, the pipeline is
 * the ExposedWorld's over fakes, and FakeIdentity verifies the session.
 */
final class InertiaSessionTest extends TestCase
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
            [new CommandEntry(new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, 'acme/probe')],
            [],
            [new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, [Surface::Rest, Surface::Inertia])],
        )));
        $app->instance(CommandCodecs::class, ExposedWorld::codecs());
        $app->instance(RunExposedCommand::class, $this->world->action());
        $app->instance(CredentialVerifier::class, $this->world->world->identity);
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
    public function it_names_the_panels_command_route_apart_from_the_profiles_own(): void
    {
        self::assertSame(self::ROUTE, route(PanelRoute::Command->value, ['command' => 'probe.rename', 'version' => 1], false));
    }

    #[Test]
    public function a_post_with_the_session_cookie_and_no_csrf_token_is_refused_and_runs_nothing(): void
    {
        $this->signIn();

        $this->submit()->assertStatus(419);

        self::assertSame([], $this->world()->world->committer->pending);
    }

    #[Test]
    public function a_post_with_the_session_cookie_and_the_csrf_token_runs_the_command_as_the_person_with_issuer_human(): void
    {
        $this->signIn();

        $this->submit(['X-CSRF-TOKEN' => $this->csrf()])
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect('/cms');

        $pending = $this->world()->world->committer->pending;

        self::assertCount(1, $pending);
        self::assertSame(IssuerKind::Human, $pending[0]->envelope->issuerKind);
        self::assertSame(IssuingSurface::Inertia, $pending[0]->envelope->surface);
        self::assertTrue($pending[0]->envelope->actor->equals($this->person()->id));
        self::assertTrue($pending[0]->envelope->onBehalfOf->isEmpty());
        self::assertSame('panel-session', $pending[0]->envelope->idempotencyKey->value);
    }

    #[Test]
    public function the_session_and_not_a_bearer_header_is_the_credential_below_the_panel(): void
    {
        $this->signIn();

        $this->submit(['X-CSRF-TOKEN' => $this->csrf(), 'Authorization' => 'Bearer '.$this->world()->credential()->reveal()])
            ->assertStatus(InertiaOutcome::STATUS);

        $pending = $this->world()->world->committer->pending;

        self::assertCount(1, $pending);
        self::assertTrue($pending[0]->envelope->actor->equals($this->person()->id));
        self::assertSame(IssuerKind::Human, $pending[0]->envelope->issuerKind);
    }

    #[Test]
    public function a_post_without_a_session_goes_to_the_login_page_as_a_full_page_load_and_runs_nothing(): void
    {
        $this->get('/cms/login')->assertOk();

        // An Inertia visit crosses the login boundary with a new document, so the addons' import
        // map and code of the page behind the login never reach the login page.
        $this->submit(['X-CSRF-TOKEN' => $this->csrf()])
            ->assertStatus(PanelSessions::FULL_PAGE)
            ->assertHeader('X-Inertia-Location', '/cms/login?reason=required');

        self::assertSame([], $this->world()->world->committer->pending);
    }

    /**
     * Starts a session of the person and visits the panel's start with it, which binds Laravel's
     * session to it.
     */
    private function signIn(): void
    {
        $session = $this->world()->world->identity->startSession($this->person()->id);
        $this->withUnencryptedCookie(app(SessionCookie::class)->name, $session->reveal());
        $this->get('/cms')->assertOk();
    }

    /**
     * Posts probe.rename as an Inertia form on the panel's start does, with the browser's cookies.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function submit(array $headers = []): TestResponse
    {
        return $this->withCredentials()->withHeaders(['X-Inertia' => 'true', ...$headers])->from('/cms')->postJson(self::ROUTE, [
            'envelope' => ['idempotency_key' => 'panel-session'],
            'command' => json_decode($this->world()->document(), false, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    private function csrf(): string
    {
        return app('session.store')->token();
    }

    private function world(): ExposedWorld
    {
        return $this->world ?? self::fail('The test has no world.');
    }

    private function person(): Actor
    {
        return $this->person ?? self::fail('The test has no person.');
    }
}
