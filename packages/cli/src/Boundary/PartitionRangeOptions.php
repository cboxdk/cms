<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reads the --from and --to options of cms:partitions:maintain into the range whose partitions
 * the command creates. Both are read with TimeOption; they come together or not at all.
 */
#[Internal]
final readonly class PartitionRangeOptions
{
    /**
     * @return PartitionRange|null null when neither option was given
     *
     * @throws InvalidArgumentException when an option is not an instant, only one is given, or
     *                                  --to is before --from
     */
    public static function parse(mixed $from, mixed $to): ?PartitionRange
    {
        $start = TimeOption::parse('from', $from);
        $end = TimeOption::parse('to', $to);

        if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable) {
            return new PartitionRange($start, $end);
        }

        if ($start instanceof DateTimeImmutable || $end instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Give both --from and --to, or neither.');
        }

        return null;
    }
}
