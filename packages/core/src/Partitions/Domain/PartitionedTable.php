<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * A range-partitioned table that the partition manager keeps (PRD 4, 4.2).
 *
 * Its partitions are named `<table>_p<suffix>`, where the suffix is the span's start in UTC:
 * `receipts_p20260101` for a day, `audit_p202601` for a month. The manager only touches
 * partitions with that name. The table has no DEFAULT partition, so a row outside the partitions
 * that exist fails loudly.
 */
#[Experimental]
final readonly class PartitionedTable
{
    /** The longest Postgres identifier, in bytes. */
    public const int MAX_IDENTIFIER_LENGTH = 63;

    /** The most partitions one call creates for one table, so a typo in a range cannot create thousands. */
    public const int MAX_PARTITIONS_PER_CALL = 1000;

    /** An unquoted identifier: lower-case letters, digits and underscores, starting with a letter or underscore. */
    public const string NAME_PATTERN = '/\A[a-z_][a-z0-9_]*\z/';

    /**
     * @param  string  $name  the table's name, unquoted and without schema; it is resolved in the owner connection's search path
     * @param  int|null  $retentionDays  how long rows are kept after their partition's span ends; null keeps every partition
     */
    public function __construct(
        public string $name,
        public PartitionKey $key,
        public PartitionInterval $interval,
        public ?int $retentionDays,
    ) {
        $longestName = self::MAX_IDENTIFIER_LENGTH - strlen('_p') - ($interval === PartitionInterval::Day ? 8 : 6);

        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > $longestName) {
            throw InvalidPartitionPolicy::tableName($name, $longestName);
        }

        if ($retentionDays !== null && $retentionDays < 1) {
            throw InvalidPartitionPolicy::retention($name, $retentionDays);
        }
    }

    /**
     * The partition for the span that holds the instant.
     */
    public function partitionAt(DateTimeImmutable $instant): Partition
    {
        $start = $this->interval->startOf($instant);

        return new Partition($this, $this->partitionName($start), $start, $this->interval->next($start));
    }

    /**
     * Every partition whose span overlaps [$from, $to], in order.
     *
     * @return list<Partition>
     */
    public function partitionsCovering(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($to < $from) {
            throw InvalidPartitionPolicy::range($from, $to);
        }

        $partitions = [];
        $start = $this->interval->startOf($from);

        while ($start <= $to) {
            if (count($partitions) === self::MAX_PARTITIONS_PER_CALL) {
                throw InvalidPartitionPolicy::rangeTooLarge($this->name, $from, $to);
            }

            $partitions[] = $this->partitionAt($start);
            $start = $this->interval->next($start);
        }

        return $partitions;
    }

    /**
     * The partition a name stands for, or null when the name is not one of this table's.
     */
    public function partitionNamed(string $name): ?Partition
    {
        $prefix = $this->name.'_p';

        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        $start = $this->interval->parseSuffix(substr($name, strlen($prefix)));

        return $start instanceof DateTimeImmutable ? $this->partitionAt($start) : null;
    }

    private function partitionName(DateTimeImmutable $start): string
    {
        return $this->name.'_p'.$this->interval->suffix($start);
    }
}
