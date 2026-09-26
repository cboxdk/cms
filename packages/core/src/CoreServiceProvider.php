<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Doctor\Adapter\CatalogPartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionLcMessagesProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Adapter\FileRegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Adapter\FrameworkRuntimeProbe;
use Cbox\Cms\Core\Doctor\Adapter\ProcessToolProbe;
use Cbox\Cms\Core\Doctor\Adapter\RedisValkeyProbe;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ChromiumCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\InvalidConfigurationCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\NodeCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PhpVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PlaywrightCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PreparedTransactionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\TransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ValkeyReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\OrderedDoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the core package in a Laravel application. Loaded through package discovery.
 *
 * Binds each contract to the implementation configured in `cms.contracts` (GUARDRAILS 2.3), loads
 * the core's migrations, binds partition maintenance to the Postgres partition manager, and
 * schedules it. Wires the registry that cms:build compiles to bootstrap/cache/cms/ (PRD 13.2), and
 * declares the core's own classes as a scan root. Wires the checks of cms:doctor (PRD 3.3, 4.2) to
 * their probes; a test swaps a probe by binding its interface.
 */
#[Internal]
final class CoreServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    /** The command the cli package registers for partition maintenance. */
    public const string PARTITIONS_COMMAND = 'cms:partitions:maintain';

    public const string PACKAGE = 'cboxdk/cms-core';

    /** Where cms:build writes the registry, below the application's bootstrap path. */
    public const string REGISTRY_CACHE = 'cache/cms';

    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/cms.php', 'cms');

        $this->app->singleton(
            Clock::class,
            static fn (Application $app): Clock => $app->make(ContractBindings::class)->resolve($app, Clock::class),
        );

        $this->app->singleton(
            IdGenerator::class,
            static fn (Application $app): IdGenerator => $app->make(ContractBindings::class)->resolve($app, IdGenerator::class),
        );

        $this->app->singleton(
            ReceiptStore::class,
            static fn (Application $app): ReceiptStore => $app->make(ContractBindings::class)->resolve($app, ReceiptStore::class),
        );

        $this->app->singleton(
            IdempotencyStore::class,
            static fn (Application $app): IdempotencyStore => $app->make(ContractBindings::class)->resolve($app, IdempotencyStore::class),
        );

        // Built on each resolution, so the policy follows the configuration.
        $this->app->bind(
            PartitionMaintenance::class,
            static fn (Application $app): PartitionMaintenance => new PostgresPartitionManager(
                $app->make(ConnectionResolverInterface::class),
                PartitionConfig::read($app->make(Repository::class)),
            ),
        );

        $this->app->bind(DeclarationScanner::class, AttributeScanner::class);

        $this->app->bind(
            RegistryCache::class,
            static fn (Application $app): RegistryCache => new FileRegistryCache($app->bootstrapPath(self::REGISTRY_CACHE), new RegistryCacheCodec),
        );

        // Read once per process from the files cms:build wrote; a missing file throws RegistryCacheMissing.
        $this->app->singleton(
            CompiledRegistry::class,
            static fn (Application $app): CompiledRegistry => $app->make(RegistryCache::class)->read(),
        );

        $this->registerDoctor();
    }

    public function boot(): void
    {
        // The core's tables. The owner role runs them (PRD 4.2); the app role has no DDL.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Every hour, so a missed run costs an hour of a 14-day runway and retention runs on time.
        // Runs never overlap: the manager holds an advisory lock in Postgres for the whole run.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command(self::PARTITIONS_COMMAND)->hourly();
        });
    }

    /**
     * The checks of cms:doctor and their probes. The checks are built per resolution, so they
     * follow the configuration; the Postgres probes share one scoped connection. An invalid
     * `cms.doctor` gives the one failing check doctor.config instead of an exception.
     */
    private function registerDoctor(): void
    {
        $this->app->bind(
            DoctorSettings::class,
            static fn (Application $app): DoctorSettings => DoctorConfig::read($app->make(Repository::class), $app->basePath()),
        );

        // One connection for every Postgres probe of a run; scoped, so a new request or job gets a new one.
        $this->app->scoped(
            DoctorConnection::class,
            static fn (Application $app): DoctorConnection => new DoctorConnection(
                $app->make(DatabaseManager::class),
                $app->make(Repository::class),
                $app->make(DoctorSettings::class)->connection,
                DoctorConnection::NAME,
                $app->make(DoctorSettings::class)->connectTimeoutSeconds,
            ),
        );

        $this->app->bind(RuntimeProbe::class, FrameworkRuntimeProbe::class);
        $this->app->bind(PostgresProbe::class, ConnectionPostgresProbe::class);
        $this->app->bind(PartitionRunwayProbe::class, CatalogPartitionRunwayProbe::class);
        $this->app->bind(ValkeyProbe::class, RedisValkeyProbe::class);
        // The owner role's connection is read by postgres.lc_messages only, so the probe has its own.
        $this->app->bind(
            LcMessagesProbe::class,
            static fn (Application $app): LcMessagesProbe => new ConnectionLcMessagesProbe(
                $app->make(DoctorConnection::class),
                new DoctorConnection(
                    $app->make(DatabaseManager::class),
                    $app->make(Repository::class),
                    $app->make(DoctorSettings::class)->ownerConnection,
                    DoctorConnection::OWNER_NAME,
                    $app->make(DoctorSettings::class)->connectTimeoutSeconds,
                ),
            ),
        );
        $this->app->bind(
            RegistryCacheProbe::class,
            static fn (Application $app): RegistryCacheProbe => new FileRegistryCacheProbe($app->make(RegistryCache::class), $app->make(DoctorSettings::class)->vendorManifest),
        );
        $this->app->bind(
            ToolProbe::class,
            static fn (Application $app): ToolProbe => new ProcessToolProbe($app->make(DoctorSettings::class)->projectPath),
        );

        $this->app->bind(static function (Application $app): DoctorChecks {
            try {
                $settings = DoctorConfig::read($app->make(Repository::class), $app->basePath());
            } catch (InvalidDoctorConfig $invalid) {
                return new OrderedDoctorChecks([new InvalidConfigurationCheck($invalid->getMessage())], []);
            }

            $runtime = $app->make(RuntimeProbe::class);
            $postgres = $app->make(PostgresProbe::class);
            $tools = $app->make(ToolProbe::class);

            return new OrderedDoctorChecks(
                runtime: [
                    new PhpVersionCheck($runtime),
                    new LaravelVersionCheck($runtime),
                    new PostgresReachableCheck($postgres),
                    new PostgresVersionCheck($postgres),
                    new AppRoleCheck($postgres),
                    new TransactionTimeoutCheck($postgres),
                    new PreparedTransactionsCheck($postgres),
                    new LcMessagesCheck($app->make(LcMessagesProbe::class)),
                    new DdlPrivilegesCheck($postgres),
                    new ValkeyReachableCheck($app->make(ValkeyProbe::class)),
                    new PartitionRunwayCheck(
                        $app->make(PartitionRunwayProbe::class),
                        $app->make(Clock::class),
                        $settings->runwayDays,
                    ),
                    new RegistryCacheCheck($app->make(RegistryCacheProbe::class)),
                ],
                dev: [
                    new NodeCheck($tools, $settings->nodeMinimum),
                    new PlaywrightCheck($tools),
                    new ChromiumCheck($tools),
                ],
            );
        });
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
