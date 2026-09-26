<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;

/**
 * What one run of partition maintenance did, and as which database role.
 */
#[Experimental]
final readonly class PartitionReport
{
    /**
     * @param  string  $role  the database role that ran the DDL
     * @param  list<PartitionChange>  $changes  in the order they were made
     * @param  list<TableRunway>  $runways  one per managed table, in policy order
     */
    public function __construct(
        public string $role,
        public array $changes,
        public array $runways,
    ) {}

    /**
     * The names of the partitions that had the given change, in order.
     *
     * @return list<string>
     */
    public function partitions(PartitionChangeKind $kind): array
    {
        return array_values(array_map(
            static fn (PartitionChange $change): string => $change->partition,
            array_filter($this->changes, static fn (PartitionChange $change): bool => $change->kind === $kind),
        ));
    }
}
