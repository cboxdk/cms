<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use DateTimeImmutable;

function daily(?int $retention = null, PartitionKey $key = PartitionKey::Uuid7): PartitionedTable
{
    return new PartitionedTable('receipts', $key, PartitionInterval::Day, $retention);
}

function monthly(?int $retention = null): PartitionedTable
{
    return new PartitionedTable('audit', PartitionKey::Timestamp, PartitionInterval::Month, $retention);
}

/**
 * @param  list<Partition>  $partitions
 * @return list<string>
 */
function names(array $partitions): array
{
    return array_map(static fn (Partition $partition): string => $partition->name, $partitions);
}

it('names a partition by the start of its span in UTC', function (): void {
    $partition = daily()->partitionAt(new DateTimeImmutable('2026-03-10T23:30:00-02:00'));

    expect($partition->name)->toBe('receipts_p20260311')
        ->and($partition->start->format(DATE_ATOM))->toBe('2026-03-11T00:00:00+00:00')
        ->and($partition->end->format(DATE_ATOM))->toBe('2026-03-12T00:00:00+00:00')
        ->and(monthly()->partitionAt(new DateTimeImmutable('2026-12-31T23:59:59.999999Z'))->name)->toBe('audit_p202612')
        ->and(monthly()->partitionAt(new DateTimeImmutable('2026-12-31T23:59:59Z'))->end->format(DATE_ATOM))->toBe('2027-01-01T00:00:00+00:00');
});

it('bounds a uuid7 partition by the lowest UUIDv7 of the first millisecond of its span and of the next', function (): void {
    $partition = daily()->partitionAt(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $start = Uuid7::unixMillisecondsOf(new DateTimeImmutable('2026-03-10T00:00:00Z'));
    $end = Uuid7::unixMillisecondsOf(new DateTimeImmutable('2026-03-11T00:00:00Z'));

    expect($partition->from())->toBe(Uuid7::lowestAt($start)->value)
        ->and($partition->to())->toBe(Uuid7::lowestAt($end)->value)
        ->and(strcmp(Uuid7::highestAt($end - 1)->value, $partition->to()))->toBeLessThan(0)
        ->and(strcmp(Uuid7::lowestAt($start)->value, $partition->from()))->toBe(0);
});

it('bounds a timestamp partition by the instants in UTC with microseconds', function (): void {
    $partition = monthly()->partitionAt(new DateTimeImmutable('2024-02-15T00:00:00Z'));

    expect($partition->from())->toBe('2024-02-01 00:00:00.000000+00:00')
        ->and($partition->to())->toBe('2024-03-01 00:00:00.000000+00:00');
});

it('lists every partition whose span overlaps a range, both ends included', function (): void {
    expect(names(daily()->partitionsCovering(new DateTimeImmutable('2024-02-27T23:59:59Z'), new DateTimeImmutable('2024-03-01T00:00:00Z'))))
        ->toBe(['receipts_p20240227', 'receipts_p20240228', 'receipts_p20240229', 'receipts_p20240301'])
        ->and(names(monthly()->partitionsCovering(new DateTimeImmutable('2025-11-30T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:00Z'))))
        ->toBe(['audit_p202511', 'audit_p202512', 'audit_p202601', 'audit_p202602'])
        ->and(names(daily()->partitionsCovering(new DateTimeImmutable('2026-01-01T05:00:00Z'), new DateTimeImmutable('2026-01-01T06:00:00Z'))))
        ->toBe(['receipts_p20260101']);
});

it('refuses a range that runs backwards or needs more than the limit of partitions', function (): void {
    expect(static fn (): array => daily()->partitionsCovering(new DateTimeImmutable('2026-01-02'), new DateTimeImmutable('2026-01-01')))
        ->toThrow(InvalidPartitionPolicy::class, 'before it starts')
        ->and(static fn (): array => daily()->partitionsCovering(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2028-12-31')))
        ->toThrow(InvalidPartitionPolicy::class, 'more than 1000 partitions of table "receipts"');

    expect(daily()->partitionsCovering(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2028-09-26')))->toHaveCount(1000);
});

it('reads its own partition names back and ignores every other name', function (): void {
    expect(daily()->partitionNamed('receipts_p20240229')?->start->format(DATE_ATOM))->toBe('2024-02-29T00:00:00+00:00')
        ->and(monthly()->partitionNamed('audit_p202602')?->end->format(DATE_ATOM))->toBe('2026-03-01T00:00:00+00:00');

    foreach (['receipts_p20230229', 'receipts_p2024022', 'receipts_p202402291', 'receipts_p202402', 'receipts_old', 'other_p20240229', 'receipts_p2024-02-2', 'receipts_x_p20240229'] as $name) {
        expect(daily()->partitionNamed($name))->toBeNull();
    }

    expect(monthly()->partitionNamed('audit_p202613'))->toBeNull()
        ->and(monthly()->partitionNamed('audit_p20260101'))->toBeNull();
});

it('expires a partition once its span ended the retention ago, and never without retention', function (): void {
    $partition = daily(7)->partitionAt(new DateTimeImmutable('2026-01-12T12:00:00Z'));

    expect($partition->isExpiredAt(new DateTimeImmutable('2026-01-19T23:59:59.999999Z')))->toBeFalse()
        ->and($partition->isExpiredAt(new DateTimeImmutable('2026-01-20T00:00:00Z')))->toBeTrue()
        ->and(daily()->partitionAt(new DateTimeImmutable('2000-01-01'))->isExpiredAt(new DateTimeImmutable('2100-01-01')))->toBeFalse()
        ->and(monthly(31)->partitionAt(new DateTimeImmutable('2026-01-15'))->isExpiredAt(new DateTimeImmutable('2026-03-04T00:00:00Z')))->toBeTrue()
        ->and(monthly(31)->partitionAt(new DateTimeImmutable('2026-01-15'))->isExpiredAt(new DateTimeImmutable('2026-03-03T23:59:59Z')))->toBeFalse();
});

it('refuses table names Postgres cannot hold with a partition suffix, and retention under a day', function (): void {
    expect(static fn (): PartitionedTable => new PartitionedTable(str_repeat('a', 54), PartitionKey::Uuid7, PartitionInterval::Day, null))
        ->toThrow(InvalidPartitionPolicy::class, 'at most 53 characters')
        ->and(static fn (): PartitionedTable => new PartitionedTable('Receipts', PartitionKey::Uuid7, PartitionInterval::Day, null))
        ->toThrow(InvalidPartitionPolicy::class, 'is not valid')
        ->and(static fn (): PartitionedTable => new PartitionedTable('cms.receipts', PartitionKey::Uuid7, PartitionInterval::Day, null))
        ->toThrow(InvalidPartitionPolicy::class, 'is not valid')
        ->and(static fn (): PartitionedTable => new PartitionedTable('receipts', PartitionKey::Uuid7, PartitionInterval::Day, 0))
        ->toThrow(InvalidPartitionPolicy::class, 'Use at least 1 day');

    expect(new PartitionedTable(str_repeat('a', 53), PartitionKey::Uuid7, PartitionInterval::Day, null)->partitionAt(new DateTimeImmutable('2026-01-01'))->name)->toHaveLength(63)
        ->and(new PartitionedTable(str_repeat('a', 55), PartitionKey::Uuid7, PartitionInterval::Month, null)->partitionAt(new DateTimeImmutable('2026-01-01'))->name)->toHaveLength(63);
});
