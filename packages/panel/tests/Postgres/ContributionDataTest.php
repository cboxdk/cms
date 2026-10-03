<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\DeskWorld;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCount;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotesAction;
use Cbox\Cms\Panel\Tests\Contributions\VisitsDesk;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The data of a panel page's contributions (PRD 13.4), on real Postgres as the app role over
 * DeskWorld: the host asks for the deferred prop ext.tally after the page rendered, and gets,
 * under each contribution's id, the result of its query run through the query pipeline as the
 * viewer, at the lower of the viewer's access and the addon's reads, so a field above the addon's
 * reads is left out though the viewer may read it. A query the pipeline rejects, over the actor's
 * budget or as a viewer without its permission, or that throws, leaves its contribution's data
 * absent, and the page answers 200. Each query is recorded per addon in telemetry.
 */
final class ContributionDataTest extends TestCase
{
    use RealPostgres;
    use VisitsDesk;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = new DeskWorld($this->app ?? self::fail('No application.'));
    }

    #[Test]
    public function it_delivers_a_field_above_the_addon_s_reads_as_omitted_while_the_viewer_may_read_it(): void
    {
        $page = $this->deskData($this->desk()->auditor);

        self::assertSame(['count' => strlen(DeskWorld::NOTE), 'summary' => TallyNotesAction::SUMMARY], self::dataOf($page)[ContributionWorld::COUNT] ?? null);
        self::assertNull($page->json('props.cms'));

        // The same viewer, reading the same query without the addon's ceiling, reads the owner.
        $result = app(QueryPipeline::class)->run(new QueryCall(new TallyNotes(DeskWorld::NOTE), $this->desk()->auditor));

        self::assertSame(ClassificationAccess::Confidential, $result->access);
        self::assertInstanceOf(TallyCount::class, $result->result);
        self::assertSame(
            '{"count":11,"owner":"'.TallyNotesAction::OWNER.'","summary":"'.TallyNotesAction::SUMMARY.'"}',
            new TallyCodecs()->encode($result->result, $result->access),
        );
    }

    #[Test]
    public function it_leaves_the_data_of_a_query_over_budget_absent_and_answers_200(): void
    {
        $page = $this->deskData($this->desk()->auditor);

        self::assertSame(200, $page->status());
        self::assertSame([ContributionWorld::COUNT], array_keys(self::dataOf($page)));
        self::assertSame([[ContributionWorld::HEAVY, 'query_over_budget']], $this->dataErrors());
        self::assertCount(2, $this->desk()->telemetry->recorded(ContributionTelemetry::DATA_DURATION));
    }

    #[Test]
    public function it_runs_the_query_as_the_viewer_so_one_without_its_permission_gets_no_data(): void
    {
        $page = $this->deskData($this->desk()->viewer);

        self::assertSame([], $page->json('props.ext.tally'));
        self::assertSame([[ContributionWorld::COUNT, 'unauthorized'], [ContributionWorld::HEAVY, 'query_over_budget']], $this->dataErrors());
    }

    #[Test]
    public function it_leaves_the_data_of_a_query_that_throws_absent_and_answers_200(): void
    {
        $page = $this->deskData($this->desk()->auditor, TallyNotesAction::THROWS);

        self::assertSame([], $page->json('props.ext.tally'));
        self::assertSame([[ContributionWorld::COUNT, null], [ContributionWorld::HEAVY, 'query_over_budget']], $this->dataErrors());
    }

    /**
     * The contribution and the code of each data error counted, in order.
     *
     * @return list<array{string|int|float|bool|null, string|int|float|bool|null}>
     */
    private function dataErrors(): array
    {
        return array_values(array_map(
            static fn (CounterRecord $counter): array => [$counter->attributes->get(ContributionTelemetry::CONTRIBUTION), $counter->attributes->get(ContributionTelemetry::ERROR_CODE)],
            array_filter($this->desk()->telemetry->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === ContributionTelemetry::DATA_ERRORS),
        ));
    }
}
