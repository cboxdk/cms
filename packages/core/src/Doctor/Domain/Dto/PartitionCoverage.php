<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\SequenceRunway;
use DateTimeImmutable;

/**
 * How far writes to a managed table can go from now: the exclusive end of the unbroken run of
 * attached partitions that starts with the partition holding now. A gap ends the run, because a
 * write in the gap fails. Null when no partition holds now.
 *
 * A table partitioned on a sequence has no time coverage; $sequence holds its runway in ids and in
 * empty partitions ahead of the sequence's current value instead.
 *
 * A table the partition manager cannot manage as it is (missing, not partitioned by range, or
 * with a DEFAULT partition) has no coverage but the reason, so one such table does not hide the
 * coverage of the others.
 */
#[Internal]
final readonly class PartitionCoverage
{
    /**
     * @param  string|null  $unmanageable  why the table cannot be managed, or null when it can
     * @param  SequenceRunway|null  $sequence  the runway of a table partitioned on a sequence, or null for one partitioned on time
     */
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
        public ?string $unmanageable = null,
        public ?SequenceRunway $sequence = null,
    ) {}

    public static function sequence(string $table, SequenceRunway $runway): self
    {
        return new self($table, null, null, $runway);
    }

    public static function unmanageable(string $table, string $reason): self
    {
        return new self($table, null, $reason);
    }

    public function isManageable(): bool
    {
        return $this->unmanageable === null;
    }
}
