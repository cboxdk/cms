<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The settings of cms:doctor, read from `cms.doctor` with its paths resolved.
 */
#[Internal]
final readonly class DoctorSettings
{
    /**
     * @param  string  $connection  the database connection of the app role that the Postgres checks use
     * @param  string  $redisConnection  the Redis connection that the Valkey check pings
     * @param  int  $connectTimeoutSeconds  how long a connection attempt to Postgres or Valkey may take
     * @param  int  $runwayDays  how many days ahead every managed table must have partitions
     * @param  string  $vendorManifest  Composer's vendor/composer/installed.json, which the registry cache must not be older than
     * @param  string  $projectPath  the directory with package.json and node_modules, for --dev
     * @param  string  $nodeMinimum  the lowest Node version --dev accepts
     */
    public function __construct(
        public string $connection,
        public string $redisConnection,
        public int $connectTimeoutSeconds,
        public int $runwayDays,
        public string $vendorManifest,
        public string $projectPath,
        public string $nodeMinimum,
    ) {}
}
