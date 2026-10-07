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
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One form instance, one changeset (PRD 6.1, GUARDRAILS 2.1): the generic command form keeps one
 * idempotency key per instance and sends it with every submit of that instance, so a form
 * submitted twice, as a double press or a retry after the answer was lost, commits once. Posting
 * the form's body twice to the Inertia profile below the panel, as the person who signed in, with
 * the same key and document, reaches the committer once; the second post is a replay that answers
 * with the first call's receipt and the same changeset. The same key with other content is refused
 * with idempotency_conflict and commits nothing, which is why the form takes a new key once a
 * submit committed. The registry exposes the test-only probe.rename version 1 on REST and Inertia,
 * over the ExposedWorld's fakes.
 */
final class FormIdempotencyTest extends TestCase
{
    private const string ROUTE = '/cms/commands/probe.rename/v1';

    private const string KEY = 'form-instance-7f3a';

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
    public function submitting_the_same_form_instance_twice_gives_one_changeset(): void
    {
        $this->signIn();
        $this->get(self::ROUTE)->assertOk();

        $this->submit(self::KEY)->assertStatus(InertiaOutcome::STATUS)->assertRedirect(self::ROUTE);
        $first = $this->receipt();
        $this->submit(self::KEY)->assertStatus(InertiaOutcome::STATUS)->assertRedirect(self::ROUTE);
        $second = $this->receipt();

        self::assertSame('committed', $first['outcome']);
        self::assertSame('committed', $second['outcome']);
        self::assertIsString($first['changeset_id']);
        self::assertSame($first['changeset_id'], $second['changeset_id']);
        self::assertCount(1, $this->world()->world->committer->pending);
        self::assertSame(self::KEY, $this->world()->world->committer->pending[0]->envelope->idempotencyKey->value);
    }

    #[Test]
    public function the_same_key_with_other_content_is_refused_and_commits_nothing_more(): void
    {
        $this->signIn();
        $this->get(self::ROUTE)->assertOk();

        $this->submit(self::KEY)->assertStatus(InertiaOutcome::STATUS);
        $this->submit(self::KEY, $this->world()->document(PipelineWorld::fields('Changed')))->assertStatus(InertiaOutcome::STATUS);

        $page = $this->page();
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $flash = is_array($page['flash'] ?? null) ? $page['flash'] : [];
        $problem = $props['problem'] ?? null;
        $receipt = $flash[InertiaOutcome::RECEIPT] ?? null;

        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertIsArray($problem);
        self::assertSame('idempotency_conflict', $problem['code']);
        self::assertCount(1, $this->world()->world->committer->pending);
    }

    /**
     * Starts a session of the person and visits the panel's start with it, which binds Laravel's
     * session to it.
     */
    private function signIn(): void
    {
        $person = $this->person ?? self::fail('The test has no person.');
        $session = $this->world()->world->identity->startSession($person->id);
        $this->withUnencryptedCookie(app(SessionCookie::class)->name, $session->reveal());
        $this->get('/cms')->assertOk();
    }

    /**
     * Posts the form's body as the command form does: an Inertia visit from the form's page with the
     * instance's key, the wait level commit and no dry run.
     *
     * @return TestResponse<Response>
     */
    private function submit(string $key, ?string $document = null): TestResponse
    {
        return $this->withCredentials()
            ->withHeaders(['X-Inertia' => 'true', 'X-CSRF-TOKEN' => app('session.store')->token()])
            ->from(self::ROUTE)
            ->postJson(self::ROUTE, [
                'envelope' => ['idempotency_key' => $key, 'dry_run' => false, 'wait_level' => 'commit'],
                'command' => json_decode($document ?? $this->world()->document(), false, 512, JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * The form's page as Inertia's client reads it after the redirect: component, props and flash.
     *
     * @return array<array-key, mixed>
     */
    private function page(): array
    {
        $page = $this->withCredentials()
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(PanelBuild::class)->version])
            ->get(self::ROUTE)
            ->assertOk()
            ->json();

        if (! is_array($page) || ($page['component'] ?? null) !== PanelPages::COMMAND_FORM) {
            throw new RuntimeException('The redirect did not render the form page.');
        }

        $page['props'] = json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        return $page;
    }

    /**
     * The receipt the last submit flashed.
     *
     * @return array<array-key, mixed>
     */
    private function receipt(): array
    {
        $flash = $this->page()['flash'] ?? null;
        $receipt = is_array($flash) ? $flash[InertiaOutcome::RECEIPT] ?? null : null;

        return is_array($receipt) ? $receipt : throw new RuntimeException('The submit flashed no receipt.');
    }

    private function world(): ExposedWorld
    {
        return $this->world ?? self::fail('The test has no world.');
    }
}
