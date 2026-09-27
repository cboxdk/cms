<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;

/**
 * How far writes to a managed table can go from now: the exclusive end of the unbroken run of
 * attached partitions that starts with the partition holding now. A gap ends the run, because a
 * write in the gap fails. Null when no partition holds now.
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
     */
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
        public ?string $unmanageable = null,
    ) {}

    public static function unmanageable(string $table, string $reason): self
    {
        return new self($table, null, $reason);
    }

    public function isManageable(): bool
    {
        return $this->unmanageable === null;
    }
}
