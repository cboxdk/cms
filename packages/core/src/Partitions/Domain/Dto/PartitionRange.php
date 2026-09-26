<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use DateTimeImmutable;

/**
 * The instants from $from to $to, both inclusive, whose partitions partition maintenance creates
 * on request: for rows that arrive with past or future keys, and for tests at any date.
 */
#[Experimental]
final readonly class PartitionRange
{
    /**
     * @throws InvalidPartitionPolicy when $to is before $from
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($to < $from) {
            throw InvalidPartitionPolicy::range($from, $to);
        }
    }
}
