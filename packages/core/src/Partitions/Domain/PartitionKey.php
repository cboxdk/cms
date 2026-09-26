<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\Uuid7;
use DateTimeImmutable;

/**
 * What a range-partitioned table is partitioned on (PRD 4, 4.1).
 *
 * Uuid7 is an id column of UUIDv7s, which sort as time: a partition for a day holds the ids made
 * in that day. Timestamp is a timestamptz column.
 */
#[Experimental]
enum PartitionKey: string
{
    case Uuid7 = 'uuid7';
    case Timestamp = 'timestamp';

    /**
     * The partition bound for an instant, as the text of a Postgres literal without quotes.
     *
     * A range partition includes its lower bound and excludes its upper bound. For Uuid7 the bound
     * is the lowest UUIDv7 of the instant's millisecond, so a partition from one day's start to
     * the next holds every id made in the day and none made after it.
     */
    public function bound(DateTimeImmutable $instant): string
    {
        return match ($this) {
            self::Uuid7 => Uuid7::lowestAt(Uuid7::unixMillisecondsOf($instant))->value,
            self::Timestamp => $instant->format('Y-m-d H:i:s.uP'),
        };
    }
}
