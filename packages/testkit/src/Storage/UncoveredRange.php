<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Storage;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A range of partition keys, both ends inclusive, that no partition covers in a fake store (PRD 4,
 * 4.2). A database store has no DEFAULT partition, so a write outside its partitions throws
 * PartitionMissing; the fakes model that with the ranges a test uncovers.
 */
#[Internal]
final readonly class UncoveredRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($to < $from) {
            throw new InvalidArgumentException(sprintf(
                'An uncovered range ends at or after it starts, got %s to %s.',
                $from->format('Y-m-d\TH:i:s.uP'),
                $to->format('Y-m-d\TH:i:s.uP'),
            ));
        }
    }

    public function contains(DateTimeImmutable $at): bool
    {
        return $at >= $this->from && $at <= $this->to;
    }

    /**
     * Throws PartitionMissing for the table when one of the ranges contains $at.
     *
     * @param  list<self>  $ranges
     *
     * @throws PartitionMissing
     */
    public static function check(array $ranges, string $table, DateTimeImmutable $at): void
    {
        foreach ($ranges as $range) {
            if ($range->contains($at)) {
                throw PartitionMissing::forTable($table);
            }
        }
    }

    /**
     * The time in a UUIDv7's first 48 bits, the partition key of a table partitioned on the id.
     */
    public static function timeOf(int $unixMilliseconds): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('@%d.%03d', intdiv($unixMilliseconds, 1000), $unixMilliseconds % 1000));
    }
}
