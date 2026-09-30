<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

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
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Http\Tests\Rest\Support\RestWorld;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The REST surface end to end over HTTP (GUARDRAILS 2.1: JSON and problem details; PRD 6.1, 8.8).
 * cms:build's action compiles the registry from the REST fixtures, and the routes of its rest.php
 * are registered as an application registers them. The test-only command probe.rename runs
 * through its compiled route, POST /v1/commands/probe.rename/v1, with the ExposedWorld's command
 * pipeline and fakes (GUARDRAILS 9), as the service actor with a Bearer credential; the test-only
 * query probe.cards runs through GET /v1/queries/probe.cards/v1 with the QueryWorld's query
 * pipeline. It covers success, a field error, a version conflict, a dry run and a receipt that did
 * not reach its wait level, with receipt and problem details bodies, and the requests the surface
 * refuses before anything runs.
 */
final class RestSurfaceTest extends TestCase
{
    private const string COMMAND = '/v1/commands/probe.rename/v1';

    private const string QUERY = '/v1/queries/probe.cards/v1';

    private ?RestWorld $world = null;

    private ?CompiledRegistry $registry = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = RestWorld::build(RegistryFixtures::scratch());
        $router = app(Router::class);
        RestRoutes::register($router, $this->registry);
        $router->getRoutes()->refreshNameLookups();

        app()->instance(CommandCodecs::class, ExposedWorld::codecs());
        app()->instance(QueryCodecs::class, RestWorld::queryCodecs());
        $this->bind();
    }

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function it_registers_one_route_per_action_on_rest_from_the_compiled_table(): void
    {
        self::assertSame(
            [['GET', self::QUERY, 'cbox-cms.rest.queries.probe.cards.v1'], ['POST', self::COMMAND, 'cbox-cms.rest.commands.probe.rename.v1']],
            array_map(static fn (RestRoute $route): array => [$route->method->value, $route->path, RestRoutes::name($route)], $this->registry()->rest),
        );
        self::assertSame(self::COMMAND, route('cbox-cms.rest.commands.probe.rename.v1', [], false));
        self::assertSame(self::QUERY, route('cbox-cms.rest.queries.probe.cards.v1', [], false));
    }

    #[Test]
    public function it_answers_a_committed_command_with_200_and_the_receipt(): void
    {
        $response = $this->command('rest-success')->assertStatus(200)->assertHeader('Content-Type', 'application/json');
        $receipt = $this->body($response);

        self::assertSame('committed', $receipt['outcome']);
        self::assertIsString($receipt['changeset_id']);
        self::assertSame('commit', $receipt['wait_level']);
        self::assertSame('standard', $receipt['retention_class']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertCount(1, $this->world()->exposed->world->committer->pending);

        $envelope = $this->world()->exposed->world->committer->pending[0]->envelope;
        self::assertSame('rest-success', $envelope->idempotencyKey->value);
        self::assertSame('rest', $envelope->surface->value);
        self::assertTrue($envelope->actor->equals($this->world()->exposed->service));
    }

    #[Test]
    public function it_answers_a_field_error_with_problem_details_that_name_each_field(): void
    {
        $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red'))));

        $response = $this->command('rest-invalid', $this->world()->exposed->document($fields))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
        $problem = $this->body($response);

        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(422, $problem['status']);
        self::assertSame('docs/reference/errors.md#validation_failed', $problem['type']);
        self::assertFalse($problem['retryable']);
        self::assertSame(
            ['validation_failed -', 'validation_required fields.label', 'validation_unknown_field fields.colour'],
            $this->problemErrors($problem),
        );
        self::assertSame([], $this->world()->exposed->world->committer->pending);
    }

    #[Test]
    public function it_answers_a_body_the_command_codec_refuses_with_the_path_of_the_value(): void
    {
        $problem = $this->body($this->command('rest-unreadable', '{"entry":"not-an-id","fields":{},"home":"x","type":"y"}')->assertStatus(422));

        self::assertSame('json_invalid', $problem['code']);
        self::assertSame(['json_invalid entry'], $this->problemErrors($problem));
        self::assertSame([], $this->world()->exposed->world->committer->pending);
    }

    #[Test]
    public function it_answers_a_body_that_is_not_json_with_400(): void
    {
        $problem = $this->body($this->command('rest-malformed', '{"entry":')->assertStatus(400));

        self::assertSame('json_malformed', $problem['code']);
    }

    #[Test]
    public function it_answers_a_version_conflict_with_409_and_no_field_error(): void
    {
        $this->world()->exposed->world->commitWith(new VersionConflict(new StaleRead($this->world()->exposed->world->entry(), null, new AggregateVersion(1))));
        $this->bind();

        $problem = $this->body($this->command('rest-conflict')->assertStatus(409)->assertHeader('Content-Type', 'application/problem+json'));

        self::assertSame('version_conflict', $problem['code']);
        self::assertSame(409, $problem['status']);
        self::assertSame(['version_conflict -'], $this->problemErrors($problem));
    }

    #[Test]
    public function it_answers_a_dry_run_with_200_and_its_receipt_and_commits_nothing(): void
    {
        $receipt = $this->body($this->command('rest-dry-run', null, [OpenApiJson::DRY_RUN => 'true'])->assertStatus(200));

        self::assertSame('dry_run', $receipt['outcome']);
        self::assertNull($receipt['changeset_id']);
        self::assertSame([], $this->world()->exposed->world->committer->pending);
    }

    #[Test]
    public function it_answers_a_receipt_that_did_not_reach_its_wait_level_with_202(): void
    {
        $this->world()->exposed->world->commitWith(new Committed(Receipt::committedWaitTimeout(
            ChangesetId::fromString(FakeChangesetCommitter::CHANGESET),
            WaitLevel::Origin,
            RetentionClass::Standard,
            new CommitPosition(FakeChangesetCommitter::POSITION),
        )));
        $this->bind();

        $receipt = $this->body($this->command('rest-wait', null, [OpenApiJson::WAIT_LEVEL => 'origin', OpenApiJson::CORRELATION_ID => 'trace-42'])->assertStatus(202));

        self::assertSame('committed_wait_timeout', $receipt['outcome']);
        self::assertSame(FakeChangesetCommitter::CHANGESET, $receipt['changeset_id']);
        self::assertSame('origin', $receipt['wait_level']);
        self::assertSame('origin', $this->world()->exposed->world->committer->pending[0]->envelope->waitLevel->value);
        self::assertSame('trace-42', $this->world()->exposed->world->committer->pending[0]->envelope->correlationId->value);
    }

    /**
     * @return array<string, array{array<string, string>, int, string, non-empty-string}>
     */
    public static function refusedEnvelopes(): array
    {
        return [
            'no idempotency key' => [[OpenApiJson::IDEMPOTENCY_KEY => ''], 400, 'idempotency_key_required', 'The request has no Idempotency-Key header.'],
            'an idempotency key with a space' => [[OpenApiJson::IDEMPOTENCY_KEY => 'two words'], 400, 'idempotency_key_required', 'The header Idempotency-Key '],
            'an unknown wait level' => [[OpenApiJson::WAIT_LEVEL => 'soon'], 400, 'request_header_invalid', 'The header Cbox-Wait-Level '],
            'a dry run that is not true or false' => [[OpenApiJson::DRY_RUN => 'yes'], 400, 'request_header_invalid', 'The header Cbox-Dry-Run is "yes". It is true or false.'],
            'a correlation id that is too long' => [[OpenApiJson::CORRELATION_ID => str_repeat('x', 129)], 400, 'request_header_invalid', 'The header Cbox-Correlation-Id '],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  non-empty-string  $detail
     */
    #[Test]
    #[DataProvider('refusedEnvelopes')]
    public function it_refuses_an_envelope_header_it_cannot_read_before_anything_runs(array $headers, int $status, string $code, string $detail): void
    {
        $problem = $this->body($this->command('rest-envelope', null, $headers)->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json'));

        self::assertSame($code, $problem['code']);
        self::assertIsString($problem['detail']);
        self::assertStringStartsWith($detail, $problem['detail']);
        self::assertSame([$code.' -'], $this->problemErrors($problem));
        self::assertSame([], $this->world()->exposed->contexts->asked);
        self::assertSame([], $this->world()->exposed->world->committer->pending);
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
    public function it_answers_a_refused_credential_and_a_command_without_one_with_their_catalog_codes(?string $token, string $code, int $status): void
    {
        $problem = $this->body($this->command('rest-credential', null, [], $token === null ? null : new TransportCredential($token))->assertStatus($status));

        self::assertSame($code, $problem['code']);
        self::assertSame([], $this->world()->exposed->world->committer->pending);
    }

    #[Test]
    public function it_answers_a_read_with_200_and_the_result_stripped_to_the_callers_classification_access(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->world()->reads->credential->reveal()])
            ->get(self::QUERY.'?query='.rawurlencode('{"rows":2}'))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        self::assertSame([['label', 'memo', 'note'], ['label', 'memo', 'note']], $this->cardFields($this->body($response)));
        self::assertSame(1, $this->world()->reads->transaction->commits);
    }

    #[Test]
    public function it_reads_as_the_anonymous_principal_without_a_credential_and_an_empty_query_without_the_parameter(): void
    {
        $body = $this->body($this->get(self::QUERY)->assertStatus(200));

        self::assertSame([['label']], $this->cardFields($body));
    }

    #[Test]
    public function it_answers_a_read_over_budget_with_its_problem(): void
    {
        $problem = $this->body($this->get(self::QUERY.'?query='.rawurlencode('{"rows":3}'))->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json'));

        self::assertSame('query_over_budget', $problem['code']);
        self::assertSame(['query_over_budget -'], $this->problemErrors($problem));
        self::assertSame([], $this->world()->reads->library->handled);
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function refusedQueries(): array
    {
        return [
            'not JSON' => ['?query='.rawurlencode('{"rows":'), 400, 'json_malformed', 'json_malformed query'],
            'a row count out of range' => ['?query='.rawurlencode('{"rows":0}'), 422, 'json_invalid', 'json_invalid query.rows'],
            'an unknown key' => ['?query='.rawurlencode('{"pages":1}'), 422, 'json_invalid', 'json_invalid query'],
            'two values' => ['?query[]=1&query[]=2', 400, 'json_malformed', 'json_malformed query'],
        ];
    }

    #[Test]
    #[DataProvider('refusedQueries')]
    public function it_refuses_a_query_document_its_codec_refuses_before_anything_runs(string $parameters, int $status, string $code, string $error): void
    {
        $problem = $this->body($this->get(self::QUERY.$parameters)->assertStatus($status));

        self::assertSame($code, $problem['code']);
        self::assertSame([$error], $this->problemErrors($problem));
        self::assertSame([], $this->world()->reads->library->handled);
    }

    #[Test]
    public function it_serves_no_route_for_a_command_or_version_the_table_does_not_list(): void
    {
        $this->postJson('/v1/commands/probe.rename/v2', [])->assertNotFound();
        $this->postJson('/v1/commands/probe.other/v1', [])->assertNotFound();
        $this->get('/v1/commands/probe.rename/v1')->assertStatus(405);
    }

    private function world(): RestWorld
    {
        return $this->world ??= new RestWorld;
    }

    private function registry(): CompiledRegistry
    {
        return $this->registry ?? throw new RuntimeException('The registry was not built.');
    }

    private function bind(): void
    {
        app()->instance(RunExposedCommand::class, $this->world()->exposed->action());
        app()->instance(QueryPipeline::class, $this->world()->queries());
    }

    /**
     * Posts the command's document to its compiled route with the idempotency key and the headers
     * given, as the service actor unless another credential, or none, is given.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function command(string $key, ?string $document = null, array $headers = [], TransportCredential|false|null $credential = false): TestResponse
    {
        $credential = $credential === false ? $this->world()->exposed->credential() : $credential;
        $headers = [OpenApiJson::IDEMPOTENCY_KEY => $key, ...$headers];

        if ($headers[OpenApiJson::IDEMPOTENCY_KEY] === '') {
            unset($headers[OpenApiJson::IDEMPOTENCY_KEY]);
        }

        if ($credential instanceof TransportCredential) {
            $headers['Authorization'] = 'Bearer '.$credential->reveal();
        }

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', self::COMMAND, [], [], [], $server, $document ?? $this->world()->exposed->document());
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function body(TestResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($body) ? $body : throw new RuntimeException('The body is not a JSON object.');
    }

    /**
     * @param  array<array-key, mixed>  $problem
     * @return list<string> each error as "<code> <field>"
     */
    private function problemErrors(array $problem): array
    {
        $errors = $problem['errors'] ?? null;

        if (! is_array($errors)) {
            throw new RuntimeException('The problem has no errors.');
        }

        return array_values(array_map(
            static fn (mixed $error): string => is_array($error) && is_string($error['code'] ?? null)
                ? $error['code'].' '.(is_string($error['field'] ?? null) ? $error['field'] : '-')
                : throw new RuntimeException('A problem error is not an object with a code.'),
            $errors,
        ));
    }

    /**
     * The owner's field handles of each card of a probe.cards result.
     *
     * @param  array<array-key, mixed>  $body
     * @return list<list<string>>
     */
    private function cardFields(array $body): array
    {
        $cards = $body['cards'] ?? null;

        if (! is_array($cards)) {
            throw new RuntimeException('The result has no cards.');
        }

        return array_values(array_map(static function (mixed $card): array {
            $fields = is_array($card) && is_array($card['fields'] ?? null) ? array_keys($card['fields']) : throw new RuntimeException('A card has no fields.');
            sort($fields);

            return array_map(strval(...), $fields);
        }, $cards));
    }
}
