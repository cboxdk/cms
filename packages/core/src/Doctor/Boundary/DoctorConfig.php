<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the settings of cms:doctor from `cms.doctor`:
 *
 *     'doctor' => [
 *         'connection' => null,              // null: the default connection
 *         'owner_connection' => null,        // null: cms.database.owner_connection
 *         'owner_role' => null,              // null: the username of owner_connection, when it is configured
 *         'maintenance_process' => false,    // true only in the process that runs migrations and maintenance
 *         'redis_connection' => 'default',
 *         'connect_timeout_seconds' => 3,
 *         'partition_runway_days' => 7,
 *         'vendor_manifest' => null,         // null: <base path>/vendor/composer/installed.json
 *         'project_path' => null,            // null: the base path
 *         'node_minimum' => '22.13.0',
 *     ],
 */
#[Internal]
final readonly class DoctorConfig
{
    public const string CONFIG_KEY = 'cms.doctor';

    /**
     * @throws InvalidDoctorConfig
     */
    public static function read(Repository $config, string $basePath): DoctorSettings
    {
        $connection = $config->get(self::CONFIG_KEY.'.connection') ?? $config->get('database.default');
        $ownerConnection = $config->get(self::CONFIG_KEY.'.owner_connection') ?? $config->get('cms.database.owner_connection');

        $ownerConnection = self::name('owner_connection', $ownerConnection);

        return new DoctorSettings(
            connection: self::name('connection', $connection),
            ownerConnection: $ownerConnection,
            ownerRole: self::ownerRole($config, $ownerConnection),
            maintenanceProcess: self::flag($config, 'maintenance_process'),
            redisConnection: self::name('redis_connection', $config->get(self::CONFIG_KEY.'.redis_connection', 'default')),
            connectTimeoutSeconds: self::positive($config, 'connect_timeout_seconds', 3),
            runwayDays: self::positive($config, 'partition_runway_days', 7),
            vendorManifest: self::path($config, 'vendor_manifest', $basePath.'/vendor/composer/installed.json'),
            projectPath: self::path($config, 'project_path', $basePath),
            nodeMinimum: self::version($config->get(self::CONFIG_KEY.'.node_minimum', '22.13.0')),
        );
    }

    private static function name(string $key, mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw InvalidDoctorConfig::value($key, 'a connection name', self::shown($value));
        }

        return $value;
    }

    /**
     * The owner role's name: cms.doctor.owner_role, or the username of the owner connection when this
     * process has it. Only the name is read; the doctor never logs in as the owner role.
     */
    private static function ownerRole(Repository $config, string $ownerConnection): ?string
    {
        $value = $config->get(self::CONFIG_KEY.'.owner_role');

        if ($value === null) {
            $username = $config->get('database.connections.'.$ownerConnection.'.username');

            return is_string($username) && $username !== '' ? $username : null;
        }

        if (! is_string($value) || $value === '') {
            throw InvalidDoctorConfig::value('owner_role', 'a role name, or null for the username of the owner connection', self::shown($value));
        }

        return $value;
    }

    private static function flag(Repository $config, string $key): bool
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key, false);

        if (! is_bool($value)) {
            throw InvalidDoctorConfig::value($key, 'true or false', self::shown($value));
        }

        return $value;
    }

    private static function positive(Repository $config, string $key, int $default): int
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key, $default);

        if (! is_int($value) || $value < 1) {
            throw InvalidDoctorConfig::value($key, 'a whole number of at least 1', self::shown($value));
        }

        return $value;
    }

    private static function path(Repository $config, string $key, string $default): string
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key) ?? $default;

        if (! is_string($value) || $value === '') {
            throw InvalidDoctorConfig::value($key, 'a path, or null for the default', self::shown($value));
        }

        return $value;
    }

    private static function version(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A\d+\.\d+\.\d+\z/', $value) !== 1) {
            throw InvalidDoctorConfig::value('node_minimum', 'a version such as "22.13.0"', self::shown($value));
        }

        return $value;
    }

    private static function shown(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
