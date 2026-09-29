<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;

/**
 * Reads the settings of cms:doctor from `cbox-cms.doctor`, and whether this process is the
 * maintenance process from the environment variable CBOX_CMS_MAINTENANCE_PROCESS (true or 1; false,
 * 0 or unset for false). The declaration is the process's own: the web, queue and maintenance processes
 * may share one configuration cache, so a setting in it would declare every one of them.
 *
 *     'doctor' => [
 *         'connection' => null,              // null: the default connection
 *         'owner_connection' => null,        // null: cbox-cms.database.owner_connection
 *         'owner_role' => null,              // null: the username of owner_connection, when it is configured
 *         'redis_connection' => 'default',
 *         'connect_timeout_seconds' => 3,
 *         'partition_runway_days' => 7,
 *         'partition_runway_partitions' => 1,
 *         'vendor_manifest' => null,         // null: <base path>/vendor/composer/installed.json
 *         'project_path' => null,            // null: the base path
 *         'node_minimum' => '22.13.0',
 *         'checks' => [],                    // classes of DoctorCheck, run after the core's runtime checks
 *         'dev_checks' => [],                // classes of DoctorCheck, run with --dev after the core's development checks
 *     ],
 *
 * A class in checks or dev_checks must exist and implement DoctorCheck; the container builds it.
 */
#[Internal]
final readonly class DoctorConfig
{
    public const string CONFIG_KEY = 'cbox-cms.doctor';

    /** The checks an application or addon adds to the runtime checks. */
    public const string CHECKS = 'checks';

    /** The checks an application or addon adds to the development checks of --dev. */
    public const string DEV_CHECKS = 'dev_checks';

    /**
     * @throws InvalidDoctorConfig
     */
    public static function read(Repository $config, string $basePath): DoctorSettings
    {
        $connection = $config->get(self::CONFIG_KEY.'.connection') ?? $config->get('database.default');
        $ownerConnection = $config->get(self::CONFIG_KEY.'.owner_connection') ?? $config->get('cbox-cms.database.owner_connection');

        $ownerConnection = self::name('owner_connection', $ownerConnection);

        return new DoctorSettings(
            connection: self::name('connection', $connection),
            ownerConnection: $ownerConnection,
            ownerRole: self::ownerRole($config, $ownerConnection),
            maintenanceProcess: self::maintenanceProcess(),
            redisConnection: self::name('redis_connection', $config->get(self::CONFIG_KEY.'.redis_connection', 'default')),
            connectTimeoutSeconds: self::positive($config, 'connect_timeout_seconds', 3),
            runwayDays: self::positive($config, 'partition_runway_days', 7),
            runwayPartitions: self::positive($config, 'partition_runway_partitions', PartitionRunwayCheck::DEFAULT_RUNWAY_PARTITIONS),
            vendorManifest: self::path($config, 'vendor_manifest', $basePath.'/vendor/composer/installed.json'),
            projectPath: self::path($config, 'project_path', $basePath),
            nodeMinimum: self::version($config->get(self::CONFIG_KEY.'.node_minimum', '22.13.0')),
            checks: self::checks($config, self::CHECKS),
            devChecks: self::checks($config, self::DEV_CHECKS),
        );
    }

    /**
     * A list of class names, each of a class that implements DoctorCheck. Only the names are read;
     * the container builds the checks when the doctor's list is made.
     *
     * @return list<class-string<DoctorCheck>>
     */
    private static function checks(Repository $config, string $key): array
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key, []);

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidDoctorConfig::value($key, 'a list of class names of doctor checks', self::shown($value));
        }

        $checks = [];

        foreach ($value as $index => $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, DoctorCheck::class)) {
                throw InvalidDoctorConfig::value($key.'.'.$index, 'the name of a class that implements '.DoctorCheck::class, self::shown($class));
            }

            $checks[] = $class;
        }

        return $checks;
    }

    private static function name(string $key, mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw InvalidDoctorConfig::value($key, 'a connection name', self::shown($value));
        }

        return $value;
    }

    /**
     * The owner role's name: cbox-cms.doctor.owner_role, or the username of the owner connection when this
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

    /**
     * CBOX_CMS_MAINTENANCE_PROCESS from the process's environment, as Laravel reads it: true or 1,
     * and false, 0, empty or unset for false.
     */
    private static function maintenanceProcess(): bool
    {
        $value = Env::get(OwnerCredentialsCheck::MAINTENANCE_VARIABLE);

        return match ($value) {
            true, '1' => true,
            false, '0', '', null => false,
            default => throw InvalidDoctorConfig::variable(OwnerCredentialsCheck::MAINTENANCE_VARIABLE, 'true, false, 1 or 0', self::shown($value)),
        };
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
