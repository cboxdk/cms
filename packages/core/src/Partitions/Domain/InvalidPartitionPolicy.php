<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The partition policy or a requested range is not valid. It is a configuration or input error:
 * nothing was changed in the database.
 */
#[Experimental]
final class InvalidPartitionPolicy extends InvalidArgumentException
{
    public static function tableName(string $name, int $longest): self
    {
        return new self(sprintf(
            'The partitioned table name "%s" is not valid. Use lower-case letters, digits and underscores, starting with a letter or underscore, at most %d characters, so the partition names fit in 63.',
            $name,
            $longest,
        ));
    }

    public static function retention(string $table, int $days): self
    {
        return new self(sprintf(
            'The retention of table "%s" is %d days. Use at least 1 day, or null to keep every partition.',
            $table,
            $days,
        ));
    }

    public static function value(string $key, string $expected, string $given): self
    {
        return new self(sprintf(
            'The setting [cbox-cms.database.%s] is "%s". Use %s.',
            $key,
            $given,
            $expected,
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public static function duplicateTables(array $names): self
    {
        return new self(sprintf(
            'The partitioned tables list names a table twice: %s. List each table once.',
            implode(', ', array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1))),
        ));
    }

    public static function range(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(sprintf(
            'The range ends at %s, before it starts at %s. Give the earlier instant as the start.',
            $to->format(DATE_ATOM),
            $from->format(DATE_ATOM),
        ));
    }

    public static function rangeTooLarge(string $table, DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(sprintf(
            'The range from %s to %s needs more than %d partitions of table "%s". Create them in smaller ranges.',
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM),
            PartitionedTable::MAX_PARTITIONS_PER_CALL,
            $table,
        ));
    }
}
