<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\LocalizedEntries;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Testing\TestResponse;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * variant.delete through the real command pipeline on Postgres and GET /v1/resolve over HTTP (PRD
 * 8.10, 10.3): a fixture notice, of the workbench's type with localization variants, is released
 * in da and de on the site alpen and placed below its section with the slug "hafen", live in both
 * languages. Deleting da withdraws its head, removes its rows from the type table and withdraws
 * its placement in da, in one changeset, so the da URL answers 410 while the de URL still answers
 * 200 and the entry stays active. The shared variant cannot be deleted this way, a translator
 * granted de alone may not delete da, and a rebuild of the type table leaves the deleted language
 * without a row.
 */
final class VariantDeleteTest extends TestCase
{
    use RealPostgres;
    use RealValkey;

    private const string NOTICE = '0192a0c0-0000-7000-8000-0000000038e9';

    private const string PLACEMENT = '0192a0c0-0000-7000-8000-0000000038c9';

    #[Override]
    protected function tearDown(): void
    {
        EntryWorld::cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function it_deletes_da_so_the_da_url_answers_410_while_the_de_url_answers_200_and_the_entry_stays_active(): void
    {
        $alpen = $this->alpen();
        $editor = new PublishingWorld([$alpen->root], seed: 3803, granted: true);

        $result = $editor->deleteVariant($this->notice(), 'da', 1, 'delete-da');
        $changeset = $result->receipt->changesetId?->toString() ?? self::fail('The delete did not commit.');
        $superuser = StorageTables::superuser();

        self::assertSame(Outcome::Committed, $result->outcome());
        self::assertSame(1, $superuser->table('changesets')->where('command', 'variant.delete')->count());
        self::assertSame(['da withdrawn 2', 'de released 1', 'shared released 1'], $this->heads());
        self::assertSame(['de released'], $this->rows());
        self::assertSame(['da withdrawn false', 'de live true'], $this->locales());
        self::assertSame('active', $superuser->table('entries')->where('id', self::NOTICE)->value('lifecycle'));
        self::assertSame(['da withdrawn'], StorageTables::texts($superuser, "select variant || ' ' || action as value from release_log where entry_id = ?::uuid and changeset_id = ?::uuid", [self::NOTICE, $changeset]));
        self::assertSame(
            ['variant.withdrawn released', 'placement.visibility_changed live'],
            StorageTables::texts($superuser, "select type || ' ' || (data->'previous'->>'enum') as value from events where changeset_id = ?::uuid order by event_id", [$changeset]),
        );

        $da = $this->resolve('da');
        $de = $this->resolve('de');

        $da->assertStatus(410)->assertHeader('Content-Type', 'application/problem+json');
        $de->assertOk();
        self::assertSame('path_gone', $da->json('code'));
        self::assertSame(self::NOTICE, $de->json('data.cms_id'));
        self::assertSame('Der Hafen öffnet', $de->json('data.fixture_title'));
    }

    #[Test]
    public function it_refuses_a_delete_of_the_shared_variant_with_validation_failed_and_changes_nothing(): void
    {
        $alpen = $this->alpen();
        $editor = new PublishingWorld([$alpen->root], seed: 3804, granted: true);

        $result = $editor->deleteVariant($this->notice(), 'shared', 1, 'delete-shared');

        self::assertSame(['validation_failed'], $this->codes($result));
        self::assertSame('variant', $result->errors[0]->path?->toString());
        self::assertSame(['da released 1', 'de released 1', 'shared released 1'], $this->heads());
        self::assertSame(['da released', 'de released'], $this->rows());
        self::assertSame(0, StorageTables::superuser()->table('changesets')->where('command', 'variant.delete')->count());
        $this->resolve('da')->assertOk();
    }

    #[Test]
    public function it_refuses_a_translator_granted_de_alone_a_delete_of_da_and_lets_one_granted_da_delete_it(): void
    {
        $alpen = $this->alpen();
        $translator = new PublishingWorld([$alpen->root], seed: 3805, granted: true, locales: ['de']);
        $danish = new PublishingWorld([$alpen->root], seed: 3806, granted: true, locales: ['da']);

        $refused = $translator->deleteVariant($this->notice(), 'da', 1, 'translator-delete-da');
        $afterRefusal = $this->locales();
        $deleted = $danish->deleteVariant($this->notice(), 'da', 1, 'danish-delete-da');

        self::assertSame(['unauthorized'], $this->codes($refused));
        self::assertStringContainsString('in the locale da', $refused->errors[0]->message);
        self::assertSame(['da live true', 'de live true'], $afterRefusal);
        self::assertSame(Outcome::Committed, $deleted->outcome());
        self::assertSame(['da withdrawn false', 'de live true'], $this->locales());
    }

    #[Test]
    public function it_leaves_no_row_of_the_deleted_language_after_a_rebuild_of_the_type_table(): void
    {
        $alpen = $this->alpen();
        new PublishingWorld([$alpen->root], seed: 3807, granted: true)->deleteVariant($this->notice(), 'da', 1, 'delete-da-rebuild');
        $before = RebuildWorld::rows(LocalizedEntries::NOTICE);
        $rebuild = new RebuildWorld(new EntryWorld(seed: 3808), granted: $alpen->root->id, seed: 3809);
        RebuildWorld::truncate(LocalizedEntries::NOTICE);

        $report = $rebuild->rebuild(LocalizedEntries::NOTICE, 'after-delete');

        self::assertSame(['de released'], $this->rows());
        self::assertSame($before, RebuildWorld::rows(LocalizedEntries::NOTICE));
        self::assertSame(1, $report->entries());
    }

    /**
     * The site alpen at alpen.example, publishing in da and de, with its section at /news in
     * each; the notice homed there with its shared variant and both languages released, and its
     * placement below the section with the slug "hafen" in each language, live from
     * EntryWorld::NOW. The container's clock stands an hour after it.
     */
    private function alpen(): StructureSite
    {
        config()->set('cbox-cms.sites', ['alpen' => ['origin' => 'https://alpen.example', 'locales' => ['da', 'de']]]);
        PlacementWorld::seed();
        $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 3801, clock: $clock));
        $alpen = $fixtures->site('alpen', [new Locale('da'), new Locale('de')]);
        $section = $fixtures->node($alpen->root);

        foreach ($alpen->locales as $locale) {
            $fixtures->route($alpen, $locale, '/news', $section);
        }

        $shared = LocalizedEntries::shared('2026-03-10', 2);
        $da = LocalizedEntries::translated('Havnen åbner');
        $de = LocalizedEntries::translated('Der Hafen öffnet');
        LocalizedEntries::notice()->write($this->notice(), $section->id, $shared, $shared, ['da' => [$da, $da], 'de' => [$de, $de]]);

        $places = new PlacementWorld([$alpen->root], seed: 3802);
        $placement = PlacementId::fromString(self::PLACEMENT);
        $live = new TimeWindow(new DateTimeImmutable(EntryWorld::NOW));
        $results = [
            $places->place($placement, $this->notice(), $section, $alpen, ['da' => 'hafen', 'de' => 'hafen'], 'alpen-place'),
            $places->setWindow($placement, 1, $live, 'alpen-live-da', 'da'),
            $places->setWindow($placement, 2, $live, 'alpen-live-de', 'de'),
        ];

        foreach ($results as $result) {
            if ($result->outcome() !== Outcome::Committed) {
                throw new LogicException('The notice was not placed: '.implode('; ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
            }
        }

        app()->instance(Clock::class, new FakeClock(new DateTimeImmutable(EntryWorld::NOW)->modify('+1 hour')));

        return $alpen;
    }

    private function notice(): EntryId
    {
        return EntryId::fromString(self::NOTICE);
    }

    /**
     * GET /v1/resolve of the notice's path on alpen in the locale.
     *
     * @return TestResponse<Response>
     */
    private function resolve(string $locale): TestResponse
    {
        return $this->get('/v1/resolve?'.http_build_query(['site' => 'alpen.example', 'locale' => $locale, 'path' => '/news/hafen']));
    }

    /**
     * @return list<string>
     */
    private function codes(WriteResult $result): array
    {
        return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
    }

    /**
     * The heads of the notice as "<variant> <release state> <version>", sorted by variant.
     *
     * @return list<string>
     */
    private function heads(): array
    {
        return StorageTables::texts(StorageTables::superuser(), "select variant || ' ' || release_state || ' ' || version as value from variant_heads where entry_id = ?::uuid order by variant", [self::NOTICE]);
    }

    /**
     * The notice's rows of its type table as "<locale> <stage>", sorted.
     *
     * @return list<string>
     */
    private function rows(): array
    {
        return StorageTables::texts(StorageTables::superuser(), "select cms_locale || ' ' || cms_stage as value from app__fixture_notice where cms_entry_id = ?::uuid order by 1", [self::NOTICE]);
    }

    /**
     * The placement's released locales as "<locale> <visibility> <canonical>", sorted.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        return StorageTables::texts(StorageTables::superuser(), "select locale || ' ' || visibility || ' ' || canonical::text as value from placement_locales where placement_id = ?::uuid and stage = 'released' order by locale", [self::PLACEMENT]);
    }
}
