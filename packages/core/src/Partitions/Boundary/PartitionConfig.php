<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\SequencePartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\SequenceRetention;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the partition policy from `cbox-cms.database`.
 *
 *     'database' => [
 *         'owner_connection' => 'pgsql_owner',
 *         'partitions' => [
 *             'runway_days' => 14,
 *             'runway_partitions' => 2,
 *             'lock_timeout_ms' => 2000,
 *             'attempts' => 3,
 *             'backoff_ms' => 250,
 *             'tables' => [
 *                 'receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
 *                 'events' => ['key' => 'bigint', 'width' => 1_000_000, 'sequence' => 'events_event_id_seq',
 *                     'retention_days' => 30, 'retention_column' => 'occurred_at'],
 *             ],
 *         ],
 *     ],
 *
 * A table with the key `bigint` has no interval: it has a width in ids and the sequence that feeds
 * the key, and its retention_days needs retention_column, the timestamptz column the age of its
 * rows is read from.
 */
#[Internal]
final readonly class PartitionConfig
{
    public const string CONFIG_KEY = 'cbox-cms.database';

    /** The key of a table partitioned on a bigint that a sequence feeds. */
    public const string BIGINT = 'bigint';

    public static function read(Repository $config): PartitionPolicy
    {
        $owner = $config->get(self::CONFIG_KEY.'.owner_connection');

        if (! is_string($owner)) {
            throw InvalidPartitionPolicy::value('owner_connection', 'a connection name', get_debug_type($owner));
        }

        $tables = $config->get(self::CONFIG_KEY.'.partitions.tables', []);

        if (! is_array($tables)) {
            throw InvalidPartitionPolicy::value('partitions.tables', 'a map of table name to settings', get_debug_type($tables));
        }

        $managed = [];
        $sequenced = [];

        foreach ($tables as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                throw InvalidPartitionPolicy::value('partitions.tables.'.$name, 'a table name mapped to its key, interval and retention_days', get_debug_type($settings));
            }

            if (($settings['key'] ?? null) === self::BIGINT) {
                $sequenced[] = self::sequenceTable($name, $settings);
            } else {
                $managed[] = self::table($name, $settings);
            }
        }

        return new PartitionPolicy(
            ownerConnection: $owner,
            tables: $managed,
            runwayDays: self::int($config, 'runway_days', PartitionPolicy::DEFAULT_RUNWAY_DAYS),
            lockTimeoutMs: self::int($config, 'lock_timeout_ms', PartitionPolicy::DEFAULT_LOCK_TIMEOUT_MS),
            attempts: self::int($config, 'attempts', PartitionPolicy::DEFAULT_ATTEMPTS),
            backoffMs: self::int($config, 'backoff_ms', PartitionPolicy::DEFAULT_BACKOFF_MS),
            sequenceTables: $sequenced,
            runwayPartitions: self::int($config, 'runway_partitions', PartitionPolicy::DEFAULT_RUNWAY_PARTITIONS),
        );
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    private static function table(string $name, array $settings): PartitionedTable
    {
        $key = 'partitions.tables.'.$name;
        $partitionKey = PartitionKey::tryFrom(is_string($settings['key'] ?? null) ? $settings['key'] : '');
        $interval = PartitionInterval::tryFrom(is_string($settings['interval'] ?? null) ? $settings['interval'] : '');
        $retention = $settings['retention_days'] ?? null;

        if (! $partitionKey instanceof PartitionKey) {
            throw InvalidPartitionPolicy::value($key.'.key', '"uuid7", "timestamp" or "bigint"', self::shown($settings['key'] ?? null));
        }

        if (! $interval instanceof PartitionInterval) {
            throw InvalidPartitionPolicy::value($key.'.interval', '"day" or "month"', self::shown($settings['interval'] ?? null));
        }

        if ($retention !== null && ! is_int($retention)) {
            throw InvalidPartitionPolicy::value($key.'.retention_days', 'a whole number of days, or null to keep every partition', self::shown($retention));
        }

        return new PartitionedTable($name, $partitionKey, $interval, $retention);
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    private static function sequenceTable(string $name, array $settings): SequencePartitionedTable
    {
        $key = 'partitions.tables.'.$name;
        $width = $settings['width'] ?? null;
        $sequence = $settings['sequence'] ?? null;
        $retention = $settings['retention_days'] ?? null;
        $column = $settings['retention_column'] ?? null;

        if (array_key_exists('interval', $settings)) {
            throw InvalidPartitionPolicy::value($key.'.interval', 'no interval for the key "bigint", whose partitions are width ids wide', self::shown($settings['interval']));
        }

        if (! is_int($width)) {
            throw InvalidPartitionPolicy::value($key.'.width', 'a whole number of ids per partition', self::shown($width));
        }

        if (! is_string($sequence)) {
            throw InvalidPartitionPolicy::value($key.'.sequence', 'the name of the sequence that feeds the key', self::shown($sequence));
        }

        if ($retention !== null && ! is_int($retention)) {
            throw InvalidPartitionPolicy::value($key.'.retention_days', 'a whole number of days, or null to keep every partition', self::shown($retention));
        }

        if ($retention !== null && ! is_string($column)) {
            throw InvalidPartitionPolicy::value($key.'.retention_column', 'the timestamptz column the age of a row is read from, because retention_days is set', self::shown($column));
        }

        if ($retention === null && $column !== null) {
            throw InvalidPartitionPolicy::value($key.'.retention_column', 'null, because retention_days is null and every partition is kept', self::shown($column));
        }

        return new SequencePartitionedTable(
            $name,
            $width,
            $sequence,
            $retention !== null ? new SequenceRetention($name, $retention, $column) : null,
        );
    }

    private static function int(Repository $config, string $name, int $default): int
    {
        $value = $config->get(self::CONFIG_KEY.'.partitions.'.$name, $default);

        if (! is_int($value)) {
            throw InvalidPartitionPolicy::value('partitions.'.$name, 'a whole number', self::shown($value));
        }

        return $value;
    }

    private static function shown(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
