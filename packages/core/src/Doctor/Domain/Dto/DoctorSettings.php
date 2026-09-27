<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;

/**
 * The settings of cms:doctor, read from `cms.doctor` with its paths resolved.
 */
#[Internal]
final readonly class DoctorSettings
{
    /**
     * @param  string  $connection  the database connection of the app role that the Postgres checks use
     * @param  string  $ownerConnection  the database connection of the owner role; postgres.owner_credentials fails when it is configured outside the maintenance process
     * @param  ?string  $ownerRole  the name of the owner role, whose lc_messages postgres.lc_messages reads from the catalog; null when neither cms.doctor.owner_role nor the owner connection names it
     * @param  bool  $maintenanceProcess  whether this process is the one that runs migrations and partition maintenance and serves no HTTP
     * @param  string  $redisConnection  the Redis connection that the Valkey check pings
     * @param  int  $connectTimeoutSeconds  how long a connection attempt to Postgres or Valkey may take
     * @param  int  $runwayDays  how many days ahead every managed table must have partitions
     * @param  string  $vendorManifest  Composer's vendor/composer/installed.json, which the registry cache must not be older than
     * @param  string  $projectPath  the directory with package.json and node_modules, for --dev
     * @param  string  $nodeMinimum  the lowest Node version --dev accepts
     * @param  list<class-string<DoctorCheck>>  $checks  the checks an application or addon adds, run after the core's runtime checks
     * @param  list<class-string<DoctorCheck>>  $devChecks  the checks an application or addon adds to --dev, run after the core's development checks
     */
    public function __construct(
        public string $connection,
        public string $ownerConnection,
        public ?string $ownerRole,
        public bool $maintenanceProcess,
        public string $redisConnection,
        public int $connectTimeoutSeconds,
        public int $runwayDays,
        public string $vendorManifest,
        public string $projectPath,
        public string $nodeMinimum,
        public array $checks,
        public array $devChecks,
    ) {}
}
