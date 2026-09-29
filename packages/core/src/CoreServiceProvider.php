<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Doctor\Adapter\CatalogPartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionLcMessagesProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\ContainerDoctorChecks;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Adapter\FileRegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Adapter\FrameworkProcessProbe;
use Cbox\Cms\Core\Doctor\Adapter\FrameworkRuntimeProbe;
use Cbox\Cms\Core\Doctor\Adapter\IniPhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Adapter\ProcessToolProbe;
use Cbox\Cms\Core\Doctor\Adapter\RedisValkeyProbe;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Checks\AllowUrlFopenCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ChromiumCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ExtensionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\InvalidConfigurationCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\NodeCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PhpVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PlaywrightCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PreparedTransactionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\TransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ValkeyReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\OrderedDoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Cbox\Cms\Core\IdempotencyStore\Boundary\IdempotencyConfig;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Operations\Adapter\PackageOperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Adapter\LoggedHookOverruns;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\RegistryAffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\OwnerCredentialsExposed;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Operations\OperationsServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Registers the core package in a Laravel application. Loaded through package discovery.
 *
 * Binds each contract to the implementation configured in `cbox-cms.contracts` (GUARDRAILS 2.3), loads
 * the core's migrations, registers laravel-operations and binds the OperationRunner to it, binds partition maintenance to the Postgres partition manager, and
 * schedules it in a process that has the owner connection. Refuses to boot a process that serves
 * HTTP or runs queued jobs with the owner connection configured (PRD 4.2). Wires the registry that cms:build compiles to bootstrap/cache/cms/ (PRD 13.2), and
 * declares the core's own classes as a scan root. Binds the kernel's settings for idempotency keys, the
 * default wait budget, from `cbox-cms.idempotency`. Wires the checks of cms:doctor (PRD 3.3, 4.2) to
 * their probes; a test swaps a probe by binding its interface. Makes Eloquent strict for every model
 * of the process (GUARDRAILS 4.1).
 */
#[Internal]
final class CoreServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    /** The command the cli package registers for partition maintenance. */
    public const string PARTITIONS_COMMAND = 'cms:partitions:maintain';

    public const string PACKAGE = 'cboxdk/cms';

    /** Where cms:build writes the registry, below the application's bootstrap path. */
    public const string REGISTRY_CACHE = 'cache/cms';

    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/cbox-cms.php', 'cbox-cms');

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

        $this->app->singleton(
            ActorDirectory::class,
            static fn (Application $app): ActorDirectory => $app->make(ContractBindings::class)->resolve($app, ActorDirectory::class),
        );

        $this->app->singleton(
            CredentialVerifier::class,
            static fn (Application $app): CredentialVerifier => $app->make(ContractBindings::class)->resolve($app, CredentialVerifier::class),
        );

        // Built on each resolution, so the default wait budget follows the configuration.
        $this->app->bind(
            IdempotencySettings::class,
            static fn (Application $app): IdempotencySettings => IdempotencyConfig::read($app->make(Repository::class)),
        );

        // Built on each resolution, so the policy follows the configuration.
        $this->app->bind(
            PartitionMaintenance::class,
            static fn (Application $app): PartitionMaintenance => new PostgresPartitionManager(
                $app->make(ConnectionResolverInterface::class),
                PartitionConfig::read($app->make(Repository::class)),
            ),
        );

        // Long flows are operations in laravel-operations, which the kernel uses directly (GUARDRAILS 3,
        // 4.2). The core registers its provider itself instead of relying on package discovery, so its
        // contract and its migration of the operations table are there wherever the core is.
        $this->app->register(OperationsServiceProvider::class);
        $this->app->bind(OperationRunner::class, PackageOperationRunner::class);

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

        // The command pipeline's ports that the core implements: the write action of a command
        // from the compiled registry, the fields of a plan's revisions through the generated
        // validators, the command transaction on the default connection, which the receipt and
        // idempotency stores write on too, and the projections a changeset's events affect, which
        // its receipt lists (PRD 6.2 phases 1, 5 and 7, 8.4).
        $this->app->bind(WriteActions::class, RegistryWriteActions::class);
        $this->app->bind(FieldValidation::class, TypeRulesFieldValidation::class);
        $this->app->bind(
            CommandTransaction::class,
            static fn (Application $app): CommandTransaction => new ConnectionCommandTransaction($app->make(ConnectionResolverInterface::class)),
        );
        $this->app->bind(AffectedProjections::class, RegistryAffectedProjections::class);
        $this->app->bind(CommandHooks::class, RegistryCommandHooks::class);
        $this->app->singleton(Stopwatch::class, HrtimeStopwatch::class);
        $this->app->bind(HookOverruns::class, LoggedHookOverruns::class);

        $this->registerDoctor();
    }

    /**
     * @throws OwnerCredentialsExposed when the owner connection is configured in a process that serves HTTP or runs queued jobs
     */
    public function boot(): void
    {
        $this->refuseOwnerCredentialsOutsideTheConsole();

        $this->makeEloquentStrict();

        // The core's tables. The owner role runs them (PRD 4.2); the app role has no DDL.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Every hour, so a missed run costs an hour of a 14-day runway and retention runs on time.
        // Runs never overlap: the manager holds an advisory lock in Postgres for the whole run.
        // Only in a process that has the owner connection: the owner credentials belong to the
        // maintenance process alone (PRD 4.2), so the web and queue processes schedule nothing.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule, Application $app): void {
            if (self::ownerConnectionConfigured($app->make(Repository::class))) {
                $schedule->command(self::PARTITIONS_COMMAND)->hourly();
            }
        });
    }

    /**
     * Eloquent's strictness for every model of the process, set once here and never per model
     * (GUARDRAILS 4.1). Reading an attribute the model did not load and mass-assigning an
     * attribute that is not fillable throw in every environment, production included, so a
     * missing column or a dropped value never passes as null or silence. Loading a relation
     * lazily throws outside production; in production it is logged as an error and the relation
     * loads, so an N+1 found there costs a log line, not a failed request.
     *
     * The violation handlers are set on each boot, to null outside production, because they are
     * static and would otherwise outlive the application that set them.
     */
    private function makeEloquentStrict(): void
    {
        Model::preventAccessingMissingAttributes();
        Model::handleMissingAttributeViolationUsing(null);
        Model::preventSilentlyDiscardingAttributes();
        Model::handleDiscardedAttributeViolationUsing(null);
        Model::preventLazyLoading();

        // An application without an environment, as a bare container is, counts as not production.
        if (! $this->app->bound('env') || $this->app->environment('production') !== true) {
            Model::handleLazyLoadingViolationUsing(null);

            return;
        }

        $app = $this->app;

        Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation, LazyLoadingViolationException $violation) use ($app): void {
            $app->make(LoggerInterface::class)->error($violation->getMessage(), [
                'model' => $model::class,
                'relation' => $relation,
            ]);
        });
    }

    /**
     * Whether this process has the owner role's connection, the one cbox-cms.database.owner_connection names.
     */
    public static function ownerConnectionConfigured(Repository $config): bool
    {
        $owner = $config->get('cbox-cms.database.owner_connection');

        return is_string($owner) && $owner !== '' && is_array($config->get('database.connections.'.$owner));
    }

    /**
     * The owner role's credentials belong to the maintenance process alone (PRD 4.2). A process
     * that serves HTTP or runs queued jobs, with the owner connection in its configuration, stops
     * here instead of running a request or a job next to them. It is decided from the process
     * itself (ProcessWorkload), not from a setting the processes could share through one
     * configuration cache. Console processes boot: the migrations, the scheduler and cms:doctor,
     * whose postgres.owner_credentials asks the maintenance process to declare itself.
     *
     * @throws OwnerCredentialsExposed
     */
    private function refuseOwnerCredentialsOutsideTheConsole(): void
    {
        $connections = $this->configuredOwnerConnections($this->app->make(Repository::class));

        if ($connections === []) {
            return;
        }

        $workload = ProcessWorkload::of($this->app);

        if (! $workload->mayHoldOwnerCredentials()) {
            throw OwnerCredentialsExposed::in($workload, $connections[0]);
        }
    }

    /**
     * The owner connections that are configured in this process: the one
     * cbox-cms.database.owner_connection names, and the one cbox-cms.doctor.owner_connection names
     * when it names another.
     *
     * @return list<string>
     */
    private function configuredOwnerConnections(Repository $config): array
    {
        $configured = [];

        foreach ([$config->get('cbox-cms.database.owner_connection'), $config->get(DoctorConfig::CONFIG_KEY.'.owner_connection')] as $name) {
            if (is_string($name) && $name !== '' && ! in_array($name, $configured, true) && is_array($config->get('database.connections.'.$name))) {
                $configured[] = $name;
            }
        }

        return $configured;
    }

    /**
     * The checks of cms:doctor and their probes. The checks are built per resolution, so they
     * follow the configuration; the Postgres probes share one scoped connection. The checks that
     * an application or addon names in `cbox-cms.doctor.checks` run after the core's runtime checks, and
     * those in `cbox-cms.doctor.dev_checks` after the core's development checks. An invalid
     * `cbox-cms.doctor`, or a named check that cannot be used, gives the one failing check doctor.config
     * instead of an exception.
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
        $this->app->bind(PhpSettingsProbe::class, IniPhpSettingsProbe::class);
        $this->app->bind(PostgresProbe::class, ConnectionPostgresProbe::class);
        $this->app->bind(PartitionRunwayProbe::class, CatalogPartitionRunwayProbe::class);
        $this->app->bind(ValkeyProbe::class, RedisValkeyProbe::class);
        $this->app->bind(ProcessProbe::class, FrameworkProcessProbe::class);
        // The owner role's lc_messages is read from the catalog on the app role's connection; the
        // doctor never logs in as the owner role (PRD 4.2).
        $this->app->bind(
            LcMessagesProbe::class,
            static fn (Application $app): LcMessagesProbe => new ConnectionLcMessagesProbe(
                $app->make(DoctorConnection::class),
                $app->make(DoctorSettings::class)->ownerRole,
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

            $core = new OrderedDoctorChecks(
                runtime: [
                    new PhpVersionCheck($runtime),
                    new AllowUrlFopenCheck($app->make(PhpSettingsProbe::class)),
                    new LaravelVersionCheck($runtime),
                    new PostgresReachableCheck($postgres),
                    new PostgresVersionCheck($postgres),
                    new AppRoleCheck($postgres),
                    new TransactionTimeoutCheck($postgres),
                    new PreparedTransactionsCheck($postgres),
                    new LcMessagesCheck($app->make(LcMessagesProbe::class)),
                    new DdlPrivilegesCheck($postgres),
                    new RowSecurityCheck($postgres),
                    new ExtensionsCheck($postgres),
                    new ValkeyReachableCheck($app->make(ValkeyProbe::class)),
                    new PartitionRunwayCheck(
                        $app->make(PartitionRunwayProbe::class),
                        $app->make(Clock::class),
                        $settings->runwayDays,
                        $settings->runwayPartitions,
                    ),
                    new RegistryCacheCheck($app->make(RegistryCacheProbe::class)),
                    new OwnerCredentialsCheck($app->make(ProcessProbe::class), $settings->ownerConnection, $settings->maintenanceProcess),
                ],
                dev: [
                    new NodeCheck($tools, $settings->nodeMinimum),
                    new PlaywrightCheck($tools),
                    new ChromiumCheck($tools),
                ],
            );

            // The checks an application or addon names in cbox-cms.doctor.checks and dev_checks, after
            // the core's, under the same rules. A check that cannot be used gives doctor.config.
            try {
                $configured = new ContainerDoctorChecks($app);

                return $core->with(
                    $configured->build(DoctorConfig::CHECKS, $settings->checks),
                    $configured->build(DoctorConfig::DEV_CHECKS, $settings->devChecks),
                );
            } catch (InvalidDoctorConfig $invalid) {
                return new OrderedDoctorChecks([new InvalidConfigurationCheck($invalid->getMessage())], []);
            } catch (InvalidDoctorCheck $invalid) {
                return new OrderedDoctorChecks([new InvalidConfigurationCheck(InvalidDoctorConfig::order($invalid)->getMessage())], []);
            }
        });
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
