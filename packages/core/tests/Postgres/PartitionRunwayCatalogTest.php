<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionRunway;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use DateTimeImmutable;

/*
 * The runway on real Postgres (PRD 4, 4.2): the partition manager's report, read as the owner
 * role, and the doctor's partitions.runway probe, read as the app role, measure the same catalog
 * with PartitionRunway and end at the same gap.
 */

beforeEach(function (): void {
    PartitionScratch::create();
    PartitionScratch::manage([PartitionScratch::UUID_TABLE => PartitionScratch::daily()], ['runway_days' => 2]);
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    PartitionScratch::drop();
});

function scratchRange(string $from, string $to): PartitionRange
{
    return new PartitionRange(new DateTimeImmutable($from), new DateTimeImmutable($to));
}

it('gives the report and the doctor the same runway, which ends at a detached partition', function (): void {
    $now = PartitionScratch::clockAt('2026-01-01T10:00:00Z')->now();
    app(MaintainPartitions::class)->cover(scratchRange('2026-01-01T00:00:00Z', '2026-01-05T00:00:00Z'));
    PartitionScratch::owner()->statement('alter table partition_scratch detach partition partition_scratch_p20260103');

    $report = app(MaintainPartitions::class)->cover(scratchRange('2026-01-04T00:00:00Z', '2026-01-05T00:00:00Z'));
    $coverage = app(PartitionRunwayProbe::class)->coverage($now);

    $table = new PartitionedTable(PartitionScratch::UUID_TABLE, PartitionKey::Uuid7, PartitionInterval::Day, null);
    $attached = array_values(array_filter(array_map($table->partitionNamed(...), PartitionScratch::partitions(PartitionScratch::UUID_TABLE))));
    $expected = PartitionRunway::end($table, $attached, $now)?->format(DATE_ATOM);

    expect($expected)->toBe('2026-01-03T00:00:00+00:00')
        ->and(array_map(static fn (TableRunway $runway): array => [$runway->table, $runway->coveredUntil?->format(DATE_ATOM)], $report->runways))
        ->toBe([[PartitionScratch::UUID_TABLE, $expected]])
        ->and(array_map(static fn (PartitionCoverage $runway): array => [$runway->table, $runway->coveredUntil?->format(DATE_ATOM)], $coverage))
        ->toBe([[PartitionScratch::UUID_TABLE, $expected]]);

    PartitionScratch::owner()->statement('drop table partition_scratch_p20260103');
    $closed = app(MaintainPartitions::class)->cover(scratchRange('2026-01-03T00:00:00Z', '2026-01-03T00:00:00Z'));

    expect($closed->runways[0]->coveredUntil?->format(DATE_ATOM))->toBe('2026-01-06T00:00:00+00:00')
        ->and(app(PartitionRunwayProbe::class)->coverage($now)[0]->coveredUntil?->format(DATE_ATOM))->toBe('2026-01-06T00:00:00+00:00');
});

it('names the doctor\'s own connection when the doctor cannot find a managed table, and still reads the others', function (): void {
    PartitionScratch::clockAt('2026-01-01T10:00:00Z');
    app(MaintainPartitions::class)->cover(scratchRange('2026-01-01T00:00:00Z', '2026-01-03T00:00:00Z'));
    PartitionScratch::manage([
        'partition_scratch_nowhere' => PartitionScratch::daily(),
        PartitionScratch::UUID_TABLE => PartitionScratch::daily(),
    ]);

    $coverage = app(PartitionRunwayProbe::class)->coverage(new DateTimeImmutable('2026-01-01T10:00:00Z'));

    expect(array_map(static fn (PartitionCoverage $runway): array => [$runway->table, $runway->coveredUntil?->format(DATE_ATOM), $runway->unmanageable], $coverage))->toBe([
        ['partition_scratch_nowhere', null, '[partition_table_unmanageable] The table "partition_scratch_nowhere" is listed in [cbox-cms.database.partitions.tables] but does not exist in the search path of the connection [cms_doctor]. Run the migrations first.'],
        [PartitionScratch::UUID_TABLE, '2026-01-04T00:00:00+00:00', null],
    ]);
});
