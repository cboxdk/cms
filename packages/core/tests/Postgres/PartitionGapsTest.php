<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateTimeImmutable;

/*
 * PartitionGaps, the uncover() hook of the Postgres store harnesses: it drops the partitions of
 * every managed table whose span overlaps the range, and only those, so a write in the range
 * throws PartitionMissing even when an earlier test covered it.
 */

it('drops every managed partition that overlaps the range and keeps the others', function (): void {
    app(PartitionFixtures::class)->cover(new DateTimeImmutable('2033-03-30T00:00:00Z'), new DateTimeImmutable('2033-04-02T00:00:00Z'));

    PartitionGaps::open(new DateTimeImmutable('2033-03-31T12:00:00Z'), new DateTimeImmutable('2033-04-01T01:00:00Z'));

    foreach (['receipts_standard', 'receipt_projections_standard', 'idempotency_keys'] as $table) {
        expect(PartitionScratch::exists($table.'_p20330330'))->toBeTrue()
            ->and(PartitionScratch::exists($table.'_p20330331'))->toBeFalse()
            ->and(PartitionScratch::exists($table.'_p20330401'))->toBeFalse()
            ->and(PartitionScratch::exists($table.'_p20330402'))->toBeTrue();
    }

    foreach (['receipts_evidence', 'receipt_projections_evidence'] as $table) {
        expect(PartitionScratch::exists($table.'_p203303'))->toBeFalse()
            ->and(PartitionScratch::exists($table.'_p203304'))->toBeFalse();
    }
});

it('leaves a range without partitions as it is', function (): void {
    PartitionGaps::open(new DateTimeImmutable('2034-06-01T00:00:00Z'), new DateTimeImmutable('2034-06-01T23:59:59Z'));

    expect(PartitionScratch::exists('idempotency_keys_p20340601'))->toBeFalse();
});
