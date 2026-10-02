<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /v1/resolve over HTTP on Postgres and Valkey (PRD 8.9, 8.10, 8.12, MILESTONES M1 point 5),
 * through the workbench's route, the container's DeliverPath, the query pipeline as the anonymous
 * principal, the generated record codecs and the Valkey fragment store. A fixture article, with
 * internal sources and the ciphertext of a confidential embargo, is published below north's
 * section, which the site south mounts at /national; the container's clock stands an hour after
 * the publish unless a test says otherwise.
 */
final class DeliveryResolveTest extends TestCase
{
    use RealPostgres;
    use RealValkey;

    private const string ENTRY = '0192a0c0-0000-7000-8000-0000000036e9';

    private const string PLACEMENT = '0192a0c0-0000-7000-8000-0000000036c9';

    /** The ciphertext written into the confidential embargo, 'A court order' in hex. */
    private const string EMBARGO = "'\\x4120636f757274206f72646572'::bytea";

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cbox-cms.sites', [
            'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en']],
            'south' => ['origin' => 'https://south.example', 'locales' => ['da']],
        ]);
        config()->set('cbox-cms.delivery.max_age_seconds', 300);
    }

    #[Override]
    protected function tearDown(): void
    {
        EntryWorld::cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function it_resolves_the_published_article_on_its_site_and_through_the_mount_with_the_record_the_keys_and_the_cache_headers(): void
    {
        $section = $this->world()->northSection->id->toString();

        foreach (['north.example' => '/nyheder/harbour', 'south.example' => '/national/harbour'] as $site => $path) {
            $response = $this->resolve($site, $path);
            $document = $this->document($response);

            $response->assertOk()
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeader(DeliveryOutput::SURROGATE_KEY, 'e-'.self::ENTRY.' n-'.$section)
                ->assertHeader(DeliveryOutput::SOURCE, 'miss')
                ->assertHeaderMissing('Set-Cookie')
                ->assertHeaderMissing('Vary');
            self::assertSame(['max-age=0', 'public', 's-maxage=300', 'stale-if-error=3600', 'stale-while-revalidate=30'], $this->cacheControl($response));
            self::assertEquals((object) ['canonical_url' => 'https://north.example/nyheder/harbour', 'contract' => 1, 'locale' => 'da', 'type' => 'app:fixture_article'], $this->at($document, 'meta'));
            self::assertSame(self::ENTRY, $this->at($document, 'data.cms_id'));
            self::assertSame('The harbour opens', $this->at($document, 'data.fixture_title'));
            self::assertSame(['fixture_science', 'fixture_culture'], $this->at($document, 'data.fixture_topics'));
            self::assertEquals((object) ['fixtureaddon' => (object) ['fixture_slug' => null]], $this->at($document, 'data.ext'));
        }
    }

    #[Test]
    public function it_leaves_the_confidential_and_the_internal_field_of_the_type_out_of_the_body(): void
    {
        $this->world();

        $response = $this->resolve('north.example', '/nyheder/harbour');
        $data = $this->at($this->document($response), 'data');

        $response->assertOk();
        self::assertInstanceOf(stdClass::class, $data);
        self::assertSame(
            ['cms_id', 'ext', 'fixture_body', 'fixture_featured', 'fixture_published_on', 'fixture_reading_minutes', 'fixture_slug', 'fixture_title', 'fixture_topics'],
            array_keys(get_object_vars($data)),
        );
        self::assertStringNotContainsString('fixture_embargo', (string) $response->getContent());
        self::assertStringNotContainsString('fixture_sources', (string) $response->getContent());
        self::assertStringNotContainsString('quoted', (string) $response->getContent());
    }

    #[Test]
    public function it_serves_the_second_request_from_the_fragment_without_running_the_query_pipeline(): void
    {
        $this->world();
        $telemetry = new FakeTelemetry;
        app()->instance(Telemetry::class, $telemetry);

        $first = $this->resolve('north.example', '/nyheder/harbour');
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $second = $this->resolve('north.example', '/nyheder/harbour');
        $queries = count($connection->getQueryLog());
        $connection->disableQueryLog();

        $first->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'miss');
        $second->assertOk()
            ->assertHeader(DeliveryOutput::SOURCE, 'hit')
            ->assertHeader(DeliveryOutput::SURROGATE_KEY, (string) $first->headers->get(DeliveryOutput::SURROGATE_KEY));
        self::assertSame($first->getContent(), $second->getContent());
        self::assertCount(1, $telemetry->spansNamed('path.resolve'));
        self::assertSame(0, $queries);
        self::assertSame(['max-age=0', 'public', 's-maxage=300', 'stale-if-error=3600', 'stale-while-revalidate=30'], $this->cacheControl($second));
    }

    #[Test]
    public function it_answers_a_path_where_nothing_is_placed_with_a_404_tagged_with_the_node_and_capped(): void
    {
        $structure = $this->world();

        $response = $this->resolve('north.example', '/nyheder/nothing');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader(DeliveryOutput::SURROGATE_KEY, 'n-'.$structure->northSection->id->toString());
        self::assertSame('path_not_found', $this->at($this->document($response), 'code'));
        self::assertSame(['max-age=0', 'public', 's-maxage=300'], $this->cacheControl($response));
    }

    #[Test]
    public function it_caps_a_visible_placement_at_the_end_of_its_window_without_stale_directives_before_the_removal(): void
    {
        config()->set('cbox-cms.delivery.max_age_seconds', 86400);
        $this->world($this->twoHours());

        $response = $this->resolve('north.example', '/nyheder/harbour');

        $response->assertOk();
        self::assertSame(['max-age=0', 'public', 's-maxage=3600'], $this->cacheControl($response));
    }

    #[Test]
    public function it_answers_a_window_that_has_not_opened_with_a_404_with_its_content_keys_capped_at_the_opening(): void
    {
        config()->set('cbox-cms.delivery.max_age_seconds', 86400);
        $structure = $this->world($this->twoHours(), hours: -1);

        $response = $this->resolve('north.example', '/nyheder/harbour');

        $response->assertNotFound()->assertHeader(DeliveryOutput::SURROGATE_KEY, 'e-'.self::ENTRY.' n-'.$structure->northSection->id->toString());
        self::assertSame('path_not_found', $this->at($this->document($response), 'code'));
        self::assertSame(['max-age=0', 'public', 's-maxage=3600'], $this->cacheControl($response));
        self::assertStringNotContainsString('The harbour opens', (string) $response->getContent());
    }

    #[Test]
    public function it_answers_a_window_that_has_closed_with_a_404_with_its_content_keys_capped_by_the_configured_lifetime(): void
    {
        $structure = $this->world($this->twoHours(), hours: 3);

        $response = $this->resolve('north.example', '/nyheder/harbour');
        $detail = $this->at($this->document($response), 'detail');

        $response->assertNotFound()->assertHeader(DeliveryOutput::SURROGATE_KEY, 'e-'.self::ENTRY.' n-'.$structure->northSection->id->toString());
        self::assertIsString($detail);
        self::assertStringContainsString('after_window', $detail);
        self::assertSame(['max-age=0', 'public', 's-maxage=300'], $this->cacheControl($response));
    }

    #[Test]
    public function it_answers_a_host_no_site_is_served_at_with_421_and_no_store_and_never_resolves_it(): void
    {
        $this->world();
        $telemetry = new FakeTelemetry;
        app()->instance(Telemetry::class, $telemetry);

        $response = $this->resolve('elsewhere.example', '/nyheder/harbour', headers: ['X-Forwarded-Host' => 'north.example']);

        $response->assertStatus(421)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeaderMissing(DeliveryOutput::SURROGATE_KEY);
        self::assertSame('host_not_configured', $this->at($this->document($response), 'code'));
        self::assertSame(['no-store', 'private'], $this->cacheControl($response));
        self::assertSame([], $telemetry->spansNamed('path.resolve'));
    }

    #[Test]
    public function it_explains_the_resolution_to_a_staff_actor_never_cached_and_refuses_it_without_a_credential(): void
    {
        $structure = $this->world();
        $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        $identity = PostgresIdentity::at($clock);
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 3636, clock: $clock));
        $actor = $identity->addActor(ActorClass::Service)->id;
        $fixtures->grant($actor, $fixtures->role('editor', ClassificationAccess::Internal), $structure->north->root->id);
        $credential = $identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->add(new DateInterval('P1D'))));

        $explained = $this->resolve('north.example', '/nyheder/harbour', ['debug' => '1'], ['Authorization' => 'Bearer '.$credential->reveal()]);
        $refused = $this->resolve('north.example', '/nyheder/harbour', ['debug' => '1']);
        $document = $this->document($explained);

        $explained->assertOk()->assertHeader('Content-Type', 'application/json');
        self::assertSame('resolved', $this->at($document, 'explanation.outcome'));
        self::assertSame('visible', $this->at($document, 'explanation.visibility.decision'));
        self::assertSame('https://north.example/nyheder/harbour', $this->at($document, 'explanation.canonical.url'));
        self::assertSame(self::ENTRY, $this->at($document, 'data.cms_id'));
        self::assertNull($this->at($document, 'problem'));
        self::assertStringNotContainsString('fixture_embargo', (string) $explained->getContent());
        self::assertSame(['no-store', 'private'], $this->cacheControl($explained));
        $refused->assertForbidden();
        self::assertSame('unauthorized', $this->at($this->document($refused), 'code'));
        self::assertSame(['no-store', 'private'], $this->cacheControl($refused));
    }

    /**
     * The seeded structure, the mount of north's section at /national on south, and the fixture
     * article published below north's section with the slug "harbour", in the window given or from
     * the publish on, with the ciphertext of its embargo; the container's clock $hours after the
     * publish.
     */
    private function world(?TimeWindow $window = null, int $hours = 1): PlacementStructure
    {
        $structure = PlacementWorld::seed();
        $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 936, clock: $clock));
        $fixtures->route($structure->south, new Locale('da'), '/national', $fixtures->mount($structure->south->root, $structure->northSection));

        $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
        $entry = EntryId::fromString(self::ENTRY);
        $placement = PlacementId::fromString(self::PLACEMENT);
        $results = [
            $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'deliver-entry'),
            $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour', 'deliver-place'),
            $world->publish($entry, 1, 1, $placement, 1, 'deliver-publish', $window),
        ];

        foreach ($results as $result) {
            if ($result->outcome() !== Outcome::Committed) {
                throw new LogicException('The article to deliver was not published: '.implode('; ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
            }
        }

        // The confidential embargo is stored encrypted, and the kernel has no key to write it with
        // yet, so its ciphertext is written into every row of the entry as the superuser.
        StorageTables::superuser()->table(EntryWorld::type(EntryWorld::ARTICLE)->name->table())
            ->where('cms_entry_id', self::ENTRY)
            ->update(['fixture_embargo' => DB::raw(self::EMBARGO)]);

        app()->instance(Clock::class, new FakeClock(new DateTimeImmutable(EntryWorld::NOW)->modify(sprintf('%+d hours', $hours))));

        return $structure;
    }

    /**
     * A window from the publish on, two hours long.
     */
    private function twoHours(): TimeWindow
    {
        return new TimeWindow(new DateTimeImmutable(EntryWorld::NOW), new DateTimeImmutable(EntryWorld::NOW)->modify('+2 hours'));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function resolve(string $site, string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->get('/v1/resolve?'.http_build_query(['site' => $site, 'locale' => 'da', 'path' => $path, ...$query]), $headers);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function document(TestResponse $response): stdClass
    {
        $document = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);

        return $document instanceof stdClass ? $document : throw new LogicException('The answer is not a JSON object.');
    }

    /**
     * The value at a dotted path of the document, such as `data.cms_id`.
     */
    private function at(stdClass $document, string $path): mixed
    {
        $value = $document;

        foreach (explode('.', $path) as $key) {
            self::assertInstanceOf(stdClass::class, $value, sprintf('The document has no object above %s.', $path));
            self::assertTrue(property_exists($value, $key), sprintf('The document has no %s.', $path));
            $value = $value->{$key};
        }

        return $value;
    }

    /**
     * The directives of the answer's Cache-Control, sorted.
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function cacheControl(TestResponse $response): array
    {
        $directives = array_map(trim(...), explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);

        return $directives;
    }
}
