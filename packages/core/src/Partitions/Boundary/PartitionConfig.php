<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the partition policy from `cms.database`.
 *
 *     'database' => [
 *         'owner_connection' => 'pgsql_owner',
 *         'partitions' => [
 *             'runway_days' => 14,
 *             'lock_timeout_ms' => 2000,
 *             'attempts' => 3,
 *             'backoff_ms' => 250,
 *             'tables' => [
 *                 'receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
 *             ],
 *         ],
 *     ],
 */
#[Internal]
final readonly class PartitionConfig
{
    public const string CONFIG_KEY = 'cms.database';

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

        foreach ($tables as $name => $settings) {
            $managed[] = self::table($name, $settings);
        }

        return new PartitionPolicy(
            ownerConnection: $owner,
            tables: $managed,
            runwayDays: self::int($config, 'runway_days', PartitionPolicy::DEFAULT_RUNWAY_DAYS),
            lockTimeoutMs: self::int($config, 'lock_timeout_ms', PartitionPolicy::DEFAULT_LOCK_TIMEOUT_MS),
            attempts: self::int($config, 'attempts', PartitionPolicy::DEFAULT_ATTEMPTS),
            backoffMs: self::int($config, 'backoff_ms', PartitionPolicy::DEFAULT_BACKOFF_MS),
        );
    }

    private static function table(int|string $name, mixed $settings): PartitionedTable
    {
        $key = 'partitions.tables.'.$name;

        if (! is_string($name) || ! is_array($settings)) {
            throw InvalidPartitionPolicy::value($key, 'a table name mapped to its key, interval and retention_days', get_debug_type($settings));
        }

        $partitionKey = PartitionKey::tryFrom(is_string($settings['key'] ?? null) ? $settings['key'] : '');
        $interval = PartitionInterval::tryFrom(is_string($settings['interval'] ?? null) ? $settings['interval'] : '');
        $retention = $settings['retention_days'] ?? null;

        if (! $partitionKey instanceof PartitionKey) {
            throw InvalidPartitionPolicy::value($key.'.key', '"uuid7" or "timestamp"', self::shown($settings['key'] ?? null));
        }

        if (! $interval instanceof PartitionInterval) {
            throw InvalidPartitionPolicy::value($key.'.interval', '"day" or "month"', self::shown($settings['interval'] ?? null));
        }

        if ($retention !== null && ! is_int($retention)) {
            throw InvalidPartitionPolicy::value($key.'.retention_days', 'a whole number of days, or null to keep every partition', self::shown($retention));
        }

        return new PartitionedTable($name, $partitionKey, $interval, $retention);
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
