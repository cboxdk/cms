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

        return new DoctorSettings(
            connection: self::name('connection', $connection),
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
