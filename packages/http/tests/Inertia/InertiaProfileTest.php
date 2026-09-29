<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Inertia;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Inertia\InertiaRoutes;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Inertia profile end to end over HTTP (GUARDRAILS 2.1: redirects, and errors in page props):
 * a form on the workbench's test page, /workbench/inertia, posts the test-only command
 * probe.rename to the profile's route, and the page the redirect renders carries what the call
 * left. The registry exposes probe.rename version 1 on REST and Inertia, the command pipeline is
 * the PipelineWorld's, with fakes for its ports (GUARDRAILS 9), and the call runs as the service
 * actor of the ExposedWorld, with a Bearer credential. It covers the success redirect, the field
 * errors in props, the version conflict, the dry run and a receipt that did not reach its wait
 * level.
 */
final class InertiaProfileTest extends TestCase
{
    private const string PAGE = '/workbench/inertia';

    private const string ROUTE = '/workbench/inertia/commands/probe.rename/v1';

    private ?ExposedWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(InertiaActions::class, new InertiaActions(new CompiledRegistry(
            [new CommandEntry(new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, 'acme/probe')],
            [],
            [new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, [Surface::Rest, Surface::Inertia])],
        )));
        app()->instance(CommandCodecs::class, ExposedWorld::codecs());
        $this->bindAction();
    }

    #[Test]
    public function it_renders_the_workbench_test_page_through_the_root_view(): void
    {
        $this->get(self::PAGE)
            ->assertOk()->assertSee('Cbox CMS workbench')->assertSeeHtml('data-page')->assertSeeHtml('Workbench\/Commands');
    }

    #[Test]
    public function it_redirects_back_with_the_committed_receipt_flashed_and_no_errors_or_problem(): void
    {
        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-success']))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();
        $receipt = $this->part($page, 'flash', InertiaOutcome::RECEIPT);

        self::assertSame('Workbench/Commands', $page['component']);
        self::assertSame('committed', $receipt['outcome']);
        self::assertIsString($receipt['changeset_id']);
        self::assertSame('commit', $receipt['wait_level']);
        self::assertSame([], $this->part($page, 'props', 'errors'));
        self::assertNull($this->part($page, 'props')['problem']);
        self::assertCount(1, $this->exposed()->world->committer->pending);
        self::assertSame('inertia-success', $this->exposed()->world->committer->pending[0]->envelope->idempotencyKey->value);
    }

    #[Test]
    public function it_puts_field_errors_in_the_errors_prop_and_the_catalog_codes_in_the_problem_prop(): void
    {
        $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red'))));

        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-invalid'], $this->exposed()->document($fields)))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();
        $errors = $this->part($page, 'props', 'errors');
        $problem = $this->part($page, 'props', 'problem');

        self::assertSame(['command.fields.label', 'command.fields.colour'], array_keys($errors));
        self::assertSame('is required.', $errors['command.fields.label']);
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(422, $problem['status']);
        self::assertSame('docs/reference/errors.md#validation_failed', $problem['type']);
        self::assertSame(
            ['validation_failed -', 'validation_required command.fields.label', 'validation_unknown_field command.fields.colour'],
            $this->problemErrors($problem),
        );
        self::assertSame('rejected', $this->part($page, 'flash', InertiaOutcome::RECEIPT)['outcome']);
        self::assertSame([], $this->exposed()->world->committer->pending);
    }

    #[Test]
    public function it_puts_the_errors_of_a_body_it_cannot_read_at_their_path_in_the_body_and_runs_nothing(): void
    {
        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-unreadable', 'wait_level' => 'soon']))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();
        $problem = $this->part($page, 'props', 'problem');

        self::assertSame(['envelope.wait_level'], array_keys($this->part($page, 'props', 'errors')));
        self::assertSame('json_invalid', $problem['code']);
        self::assertSame(422, $problem['status']);
        self::assertSame(['json_invalid envelope.wait_level'], $this->problemErrors($problem));
        self::assertArrayNotHasKey(InertiaOutcome::RECEIPT, is_array($page['flash'] ?? null) ? $page['flash'] : []);
        self::assertSame([], $this->exposed()->contexts->asked);
        self::assertSame([], $this->exposed()->world->committer->pending);
    }

    #[Test]
    public function it_answers_a_version_conflict_with_its_catalog_code_in_the_problem_prop_and_no_field_error(): void
    {
        $this->exposed()->world->commitWith(new VersionConflict(new StaleRead($this->exposed()->world->entry(), null, new AggregateVersion(1))));
        $this->bindAction();

        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-conflict']))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();
        $problem = $this->part($page, 'props', 'problem');

        self::assertSame([], $this->part($page, 'props', 'errors'));
        self::assertSame('version_conflict', $problem['code']);
        self::assertSame(409, $problem['status']);
        self::assertFalse($problem['retryable']);
        self::assertSame(['version_conflict -'], $this->problemErrors($problem));
        self::assertSame('rejected', $this->part($page, 'flash', InertiaOutcome::RECEIPT)['outcome']);
    }

    #[Test]
    public function it_flashes_the_receipt_of_a_dry_run_which_commits_nothing(): void
    {
        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-dry-run', 'dry_run' => true]))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();

        self::assertSame('dry_run', $this->part($page, 'flash', InertiaOutcome::RECEIPT)['outcome']);
        self::assertSame([], $this->part($page, 'props', 'errors'));
        self::assertNull($this->part($page, 'props')['problem']);
        self::assertSame([], $this->exposed()->world->committer->pending);
    }

    #[Test]
    public function it_flashes_a_receipt_that_did_not_reach_its_wait_level_as_committed_wait_timeout_with_no_problem(): void
    {
        $this->exposed()->world->commitWith(new Committed(Receipt::committedWaitTimeout(
            ChangesetId::fromString(FakeChangesetCommitter::CHANGESET),
            WaitLevel::Origin,
            RetentionClass::Standard,
            new CommitPosition(FakeChangesetCommitter::POSITION),
        )));
        $this->bindAction();

        $this->submit($this->exposed()->credential(), $this->body(['idempotency_key' => 'inertia-wait', 'wait_level' => 'origin']))
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect(self::PAGE);

        $page = $this->page();
        $receipt = $this->part($page, 'flash', InertiaOutcome::RECEIPT);

        self::assertSame('committed_wait_timeout', $receipt['outcome']);
        self::assertSame(FakeChangesetCommitter::CHANGESET, $receipt['changeset_id']);
        self::assertSame('origin', $receipt['wait_level']);
        self::assertSame([], $this->part($page, 'props', 'errors'));
        self::assertNull($this->part($page, 'props')['problem']);
    }

    /**
     * @return array<string, array{?string, string, int}>
     */
    public static function refusedCredentials(): array
    {
        return [
            'no credential' => [null, 'unauthorized', 403],
            'a malformed credential' => ['not-a-token', 'credential_malformed', 401],
        ];
    }

    #[Test]
    #[DataProvider('refusedCredentials')]
    public function it_answers_a_refused_credential_and_a_call_without_one_with_their_catalog_codes(?string $token, string $code, int $status): void
    {
        $this->submit($token === null ? null : new TransportCredential($token), $this->body(['idempotency_key' => 'inertia-credential']))
            ->assertStatus(InertiaOutcome::STATUS);

        $problem = $this->part($this->page(), 'props', 'problem');

        self::assertSame($code, $problem['code']);
        self::assertSame($status, $problem['status']);
        self::assertSame([], $this->exposed()->world->committer->pending);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unexposedRoutes(): array
    {
        return [
            'another command' => ['/workbench/inertia/commands/probe.other/v1'],
            'another version' => ['/workbench/inertia/commands/probe.rename/v2'],
            'a malformed name' => ['/workbench/inertia/commands/Probe.Rename/v1'],
            'version zero' => ['/workbench/inertia/commands/probe.rename/v0'],
        ];
    }

    #[Test]
    #[DataProvider('unexposedRoutes')]
    public function it_answers_404_for_a_command_or_version_it_does_not_expose(string $route): void
    {
        $this->submit(null, ['envelope' => new stdClass, 'command' => new stdClass], $route)->assertNotFound();
    }

    #[Test]
    public function it_names_its_route(): void
    {
        self::assertSame(self::ROUTE, route(InertiaRoutes::NAME, ['command' => 'probe.rename', 'version' => 1], false));
    }

    private function exposed(): ExposedWorld
    {
        return $this->world ??= new ExposedWorld;
    }

    private function bindAction(): void
    {
        app()->instance(RunExposedCommand::class, $this->exposed()->action());
    }

    /**
     * The body of a form post: the envelope fields and the command's document.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function body(array $envelope, ?string $document = null): array
    {
        return ['envelope' => $envelope, 'command' => json_decode($document ?? $this->exposed()->document(), false, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * Posts the body as an Inertia form on the test page does, with the credential when one is given.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function submit(?TransportCredential $credential, array $body, string $route = self::ROUTE): TestResponse
    {
        $headers = ['X-Inertia' => 'true'];

        if ($credential instanceof TransportCredential) {
            $headers['Authorization'] = 'Bearer '.$credential->reveal();
        }

        return $this->withHeaders($headers)->from(self::PAGE)->postJson($route, $body);
    }

    /**
     * The page the redirect renders, as Inertia's client reads it: component, props and flash.
     *
     * @return array<array-key, mixed>
     */
    private function page(): array
    {
        $page = $this->withHeaders(['X-Inertia' => 'true'])->get(self::PAGE)->assertOk()->json();

        return is_array($page) ? $page : throw new RuntimeException('The page is not a JSON object.');
    }

    /**
     * @param  array<array-key, mixed>  $page
     * @return array<array-key, mixed>
     */
    private function part(array $page, string ...$keys): array
    {
        $value = $page;

        foreach ($keys as $key) {
            $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : throw new RuntimeException(sprintf('The page has no %s.', implode('.', $keys)));
        }

        return is_array($value) ? $value : throw new RuntimeException(sprintf('%s is not an object.', implode('.', $keys)));
    }

    /**
     * @param  array<array-key, mixed>  $problem
     * @return list<string> each error as "<code> <field>"
     */
    private function problemErrors(array $problem): array
    {
        return array_map(
            static fn (mixed $error): string => is_array($error) && is_string($error['code'] ?? null)
                ? $error['code'].' '.(is_string($error['field'] ?? null) ? $error['field'] : '-')
                : throw new RuntimeException('A problem error is not an object with a code.'),
            array_values($this->part($problem, 'errors')),
        );
    }
}
