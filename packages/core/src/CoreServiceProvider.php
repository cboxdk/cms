<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Core\Access\Adapter\PostgresAccessResolver;
use Cbox\Cms\Core\Access\Adapter\TransactionalAccessContexts;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Addons\Boundary\AddonConfig;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Delivery\Actions\DeliverPath;
use Cbox\Cms\Core\Delivery\Adapter\JsonDeliveryDocuments;
use Cbox\Cms\Core\Delivery\Boundary\DeliveryConfig;
use Cbox\Cms\Core\Delivery\Domain\DeliveryAuthorizer;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Cbox\Cms\Core\Doctor\Adapter\CatalogPartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionEventLogProbe;
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
use Cbox\Cms\Core\Doctor\Domain\Checks\EventLagCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ExtensionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\IdleInTransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\InvalidConfigurationCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\NodeCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OldestTransactionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ParkedAggregatesCheck;
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
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Cbox\Cms\Core\Entries\Adapter\EntryCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryVersionLock;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Entries\Adapter\PostgresVariantVersionLock;
use Cbox\Cms\Core\Entries\Adapter\RevisionCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\VariantReleasedWriter;
use Cbox\Cms\Core\Entries\Adapter\VariantUnreleasedWriter;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Fragments\Boundary\InvalidationConfig;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Cbox\Cms\Core\IdempotencyStore\Boundary\IdempotencyConfig;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Adapter\ActorDeactivatedWriter;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Operations\Adapter\PackageOperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Adapter\LoggedHookOverruns;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Pipeline\Boundary\WaitConfig;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\RegistryAffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Placements\Adapter\PlacementCanonicalSetWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementClosedWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementCreatedWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementLocaleAddedWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementWindowSetWriter;
use Cbox\Cms\Core\Placements\Adapter\PostgresCanonicalPlacementLock;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementReader;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementSlugLock;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementVersionLock;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\OwnerCredentialsExposed;
use Cbox\Cms\Core\ReadModels\Adapter\PostgresReadModelStore;
use Cbox\Cms\Core\ReadModels\Boundary\RebuildConfig;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Adapter\ConnectionQueryTransaction;
use Cbox\Cms\Core\Reads\Adapter\PostgresReadAudit;
use Cbox\Cms\Core\Reads\Adapter\RegistryQueryActions;
use Cbox\Cms\Core\Reads\Boundary\QueryConfig;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Routing\Adapter\PostgresRouteReader;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Adapter\PostgresSeedReader;
use Cbox\Cms\Core\Seeding\Adapter\TransactionalSeedTargets;
use Cbox\Cms\Core\Seeding\Boundary\SeedContentHasher;
use Cbox\Cms\Core\Seeding\Boundary\SeedingConfig;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Cbox\Cms\Core\Seeding\Domain\SeedAuthorizer;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteVersionLock;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Adapter\RegistryLaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Boundary\RunnerConfig;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Operations\OperationsServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\ServiceProvider;
use LogicException;
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
 * default wait budget, from `cbox-cms.idempotency`, and how long a command waits for its wait level
 * after commit, from `cbox-cms.receipts`. Binds the event runner's ports and settings, from
 * `cbox-cms.events.runner`, and the rebuild's store and settings, from `cbox-cms.rebuild`. Binds the seeder's ports and its own pipeline, from `cbox-cms.seeding`. Wires the checks of cms:doctor (PRD 3.3, 4.2) to
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

        $this->app->singleton(
            FragmentStore::class,
            static fn (Application $app): FragmentStore => $app->make(ContractBindings::class)->resolve($app, FragmentStore::class),
        );

        $this->app->singleton(
            TypeTableReader::class,
            static fn (Application $app): TypeTableReader => $app->make(ContractBindings::class)->resolve($app, TypeTableReader::class),
        );

        $this->app->singleton(
            Telemetry::class,
            static fn (Application $app): Telemetry => $app->make(ContractBindings::class)->resolve($app, Telemetry::class),
        );

        // No CDN driver is configured by default; the real drivers come with full-scale
        // invalidation. Resolving CdnDriver without an entry in cbox-cms.contracts throws
        // InvalidContractBinding, which names the key to set.
        $this->app->singleton(
            CdnDriver::class,
            static fn (Application $app): CdnDriver => $app->make(ContractBindings::class)->resolve($app, CdnDriver::class),
        );

        // Built on each resolution, so the addons' service actors follow the configuration.
        $this->app->bind(
            ServiceActors::class,
            static fn (Application $app): ServiceActors => AddonConfig::read($app->make(Repository::class)),
        );

        // Built on each resolution, so the default wait budget follows the configuration.
        $this->app->bind(
            IdempotencySettings::class,
            static fn (Application $app): IdempotencySettings => IdempotencyConfig::read($app->make(Repository::class)),
        );

        // Built on each resolution, so how long a command waits for its wait level after commit
        // (PRD 8.4) follows the configuration.
        $this->app->bind(
            WaitSettings::class,
            static fn (Application $app): WaitSettings => WaitConfig::read($app->make(Repository::class)),
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
        $this->app->singleton(SavepointRefusal::class);
        $this->app->bind(
            CommandTransaction::class,
            static fn (Application $app): CommandTransaction => new ConnectionCommandTransaction(
                $app->make(ConnectionResolverInterface::class),
                $app->make(SavepointRefusal::class),
            ),
        );
        $this->app->bind(AffectedProjections::class, RegistryAffectedProjections::class);

        // What an exposed surface needs before it hands a call to the pipeline (GUARDRAILS 2.1): the
        // access context of the verified principal, read in a transaction of its own on the default
        // connection, and the codecs of each command and query it reads, which each command and
        // query registers under the tag CommandCodecs::TAG or QueryCodecs::TAG.
        $this->app->bind(AccessContexts::class, TransactionalAccessContexts::class);
        $this->app->bind(
            CommandCodecs::class,
            static fn (Application $app): CommandCodecs => new CommandCodecs(...self::tagged($app, CommandCodecs::TAG, CommandCodec::class)),
        );
        $this->app->bind(
            QueryCodecs::class,
            static fn (Application $app): QueryCodecs => new QueryCodecs(...self::tagged($app, QueryCodecs::TAG, QueryCodec::class)),
        );

        // The commit (PRD 6.2 phase 7) on the default connection, the command transaction's, with
        // the version lock of each kind of aggregate and the writer of each mutation class that
        // the container has under their tags. The core registers the actor's lock; each command
        // adds the locks and writers of its own aggregates and mutations.
        $this->app->tag([PostgresActorVersionLock::class, PostgresEntryVersionLock::class, PostgresVariantVersionLock::class, PostgresNodeVersionLock::class], VersionLocks::TAG);

        // The entry commands, entry.create, entry.revise and variant.release (PRD 5.4, 5.6, 6.4):
        // their reads, the content of a released revision the pipeline validates, and the writers
        // of their mutations, which store the entry, the revision or head snapshot, the head, the
        // published revision, the release log and the type table's rows (PRD 4.1, 11.6).
        $this->app->bind(EntryReader::class, PostgresEntryReader::class);
        $this->app->bind(RevisionContents::class, PostgresRevisionContents::class);
        $this->app->tag([EntryCreatedWriter::class, RevisionCreatedWriter::class, HeadMovedWriter::class, VariantReleasedWriter::class], MutationWriters::TAG);

        // The placement commands, placement.create and placement.set_window (PRD 5.7, 5.9, 6.4): their
        // reads, the locks of placements, sites, slugs and the canonical placement of an entry, and
        // the writers of their mutations, which store the placement, its locales, its windows and
        // the canonical flag (PRD 4.1, invariants 14 and 15).
        $this->app->bind(PlacementReader::class, PostgresPlacementReader::class);
        $this->app->tag([PostgresPlacementVersionLock::class, PostgresSiteVersionLock::class, PostgresPlacementSlugLock::class, PostgresCanonicalPlacementLock::class], VersionLocks::TAG);
        $this->app->tag([PlacementCreatedWriter::class, PlacementLocaleAddedWriter::class, PlacementWindowSetWriter::class, PlacementCanonicalSetWriter::class], MutationWriters::TAG);

        // The composite commands entry.publish and entry.unpublish (PRD 6.4) compose the planners of
        // the release and the placement commands and read through their readers; they add the
        // writers of taking a release back and of closing a placement on every site.
        $this->app->tag([VariantUnreleasedWriter::class, PlacementClosedWriter::class], MutationWriters::TAG);

        // actor.deactivate (PRD 5.16): the writer of its mutation, which deactivates the actor and
        // ends its direct grants.
        $this->app->tag([ActorDeactivatedWriter::class], MutationWriters::TAG);
        $this->app->bind(
            VersionLocks::class,
            static fn (Application $app): VersionLocks => new VersionLocks(...self::tagged($app, VersionLocks::TAG, VersionLock::class)),
        );
        $this->app->bind(
            MutationWriters::class,
            static fn (Application $app): MutationWriters => new MutationWriters(...self::tagged($app, MutationWriters::TAG, MutationWriter::class)),
        );
        $this->app->bind(ChangesetCommitter::class, PostgresChangesetCommitter::class);
        $this->app->bind(CommandHooks::class, RegistryCommandHooks::class);
        $this->app->singleton(Stopwatch::class, HrtimeStopwatch::class);
        $this->app->bind(HookOverruns::class, LoggedHookOverruns::class);

        // The query pipeline's ports that the core implements (PRD 6.2): the query action of a
        // query from the compiled registry, the read transaction on the default connection, which
        // the access resolver, the read audit and the actions' read ports use too, and the settings,
        // built on each resolution so the budgets follow the configuration. The QueryAuthorizer is not
        // bound yet; its docblock says why.
        $this->app->bind(QueryActions::class, RegistryQueryActions::class);
        $this->app->bind(
            QueryTransaction::class,
            static fn (Application $app): QueryTransaction => new ConnectionQueryTransaction($app->make(ConnectionResolverInterface::class)),
        );
        $this->app->bind(
            AccessResolver::class,
            static fn (Application $app): AccessResolver => new PostgresAccessResolver($app->make(ConnectionResolverInterface::class), new AccessCompiler),
        );
        $this->app->bind(
            ReadAudit::class,
            static fn (Application $app): ReadAudit => new PostgresReadAudit($app->make(ConnectionResolverInterface::class), $app->make(Clock::class), $app->make(IdGenerator::class)),
        );
        $this->app->bind(
            QuerySettings::class,
            static fn (Application $app): QuerySettings => QueryConfig::read($app->make(Repository::class)),
        );

        // path.resolve (PRD 5.9): its reads on the default connection, under the read's actor
        // context, and the configured sites, built on each resolution so they follow the
        // configuration.
        $this->app->bind(RouteReader::class, PostgresRouteReader::class);
        $this->app->bind(
            SiteHosts::class,
            static fn (Application $app): SiteHosts => SitesConfig::read($app->make(Repository::class)),
        );

        // The event runner's ports (PRD 7.4 to 7.8): the cursors and parked aggregates on the default
        // connection, which the subscribers write on, the subscribers of the compiled registry, real
        // time for batches and backoff, and the settings, built on each resolution so they follow
        // the configuration.
        $this->app->bind(
            SubscriptionLog::class,
            static fn (Application $app): SubscriptionLog => new PostgresSubscriptionLog($app->make(ConnectionResolverInterface::class), $app->make(Clock::class)),
        );
        $this->app->bind(LaneSubscribers::class, RegistryLaneSubscribers::class);
        $this->app->bind(Pacing::class, SystemPacing::class);
        $this->app->bind(
            RunnerSettings::class,
            static fn (Application $app): RunnerSettings => RunnerConfig::read($app->make(Repository::class)),
        );

        // The rebuild of a type's read model (PRD 4.1, invariant 22): the heads and type tables on the
        // default connection, and the settings, built on each resolution so they follow the
        // configuration.
        $this->app->bind(
            ReadModelStore::class,
            static fn (Application $app): ReadModelStore => new PostgresReadModelStore($app->make(ConnectionResolverInterface::class)),
        );
        $this->app->bind(
            RebuildSettings::class,
            static fn (Application $app): RebuildSettings => RebuildConfig::read($app->make(Repository::class)),
        );

        // The seeder (GUARDRAILS 4.3, PRD 23): seed.entries reads the chunk through the SeedReader
        // on the command transaction's connection, the run reads the nodes its actor reaches in a
        // transaction of its own, and its settings name the service actor, built on each
        // resolution. SeedDataset runs its chunks through a pipeline of its own: the kernel's, but
        // with the SeedAuthorizer, which allows seed.entries only and no field the actor may not
        // write, and the SeedContentHasher, which hashes a chunk's canonical form.
        $this->app->bind(SeedReader::class, PostgresSeedReader::class);
        $this->app->bind(SeedTargets::class, TransactionalSeedTargets::class);
        $this->app->bind(
            SeedSettings::class,
            static fn (Application $app): SeedSettings => SeedingConfig::read($app->make(Repository::class)),
        );
        $this->app->when(SeedDataset::class)
            ->needs(CommandPipeline::class)
            ->give(static fn (Application $app): CommandPipeline => new CommandPipeline(
                $app->make(WriteActions::class),
                $app->make(ActorDirectory::class),
                new SeedAuthorizer($app->make(TypeCatalog::class)),
                $app->make(TypeCatalog::class),
                $app->make(FieldValidation::class),
                $app->make(RevisionContents::class),
                $app->make(ChangesetCommitter::class),
                $app->make(IdempotencyStore::class),
                $app->make(ReceiptStore::class),
                new SeedContentHasher,
                $app->make(IdempotencySettings::class),
                $app->make(CommandTransaction::class),
                $app->make(HookRunner::class),
                $app->make(PipelineTelemetry::class),
                $app->make(AwaitWaitLevel::class),
            ));

        // The delivery API's resolve (PRD 8.9, 8.10, 8.12): its documents as canonical JSON, its
        // settings, built on each resolution, and a query pipeline of its own: the kernel's, but with
        // the DeliveryAuthorizer, which runs path.resolve for anyone and no other read.
        $this->app->bind(DeliveryDocuments::class, JsonDeliveryDocuments::class);
        $this->app->bind(
            DeliverySettings::class,
            static fn (Application $app): DeliverySettings => DeliveryConfig::read($app->make(Repository::class)),
        );
        $this->app->when(DeliverPath::class)
            ->needs(QueryPipeline::class)
            ->give(static fn (Application $app): QueryPipeline => new QueryPipeline(
                $app->make(QueryActions::class),
                $app->make(CredentialVerifier::class),
                $app->make(AccessResolver::class),
                new DeliveryAuthorizer,
                $app->make(QuerySettings::class),
                $app->make(ReadableFields::class),
                $app->make(ReadAudit::class),
                $app->make(QueryTransaction::class),
                $app->make(PipelineTelemetry::class),
            ));

        // The invalidation subscriber's settings (PRD 8.12 point 1), built on each resolution.
        $this->app->bind(
            InvalidationSettings::class,
            static fn (Application $app): InvalidationSettings => InvalidationConfig::read($app->make(Repository::class)),
        );

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
     * The services the container has under the tag, each checked to be an instance of the class.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private static function tagged(Application $app, string $tag, string $class): array
    {
        $services = [];

        foreach ($app->tagged($tag) as $service) {
            if (! $service instanceof $class) {
                throw new LogicException(sprintf('The service %s under the tag %s is not a %s.', get_debug_type($service), $tag, $class));
            }

            $services[] = $service;
        }

        return $services;
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
        $this->app->bind(EventLogProbe::class, ConnectionEventLogProbe::class);
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
                    new IdleInTransactionTimeoutCheck($postgres),
                    new PreparedTransactionsCheck($postgres),
                    new LcMessagesCheck($app->make(LcMessagesProbe::class)),
                    new DdlPrivilegesCheck($postgres),
                    new RowSecurityCheck($postgres),
                    new ExtensionsCheck($postgres),
                    new OldestTransactionCheck($postgres),
                    new ValkeyReachableCheck($app->make(ValkeyProbe::class)),
                    new PartitionRunwayCheck(
                        $app->make(PartitionRunwayProbe::class),
                        $app->make(Clock::class),
                        $settings->runwayDays,
                        $settings->runwayPartitions,
                    ),
                    new RegistryCacheCheck($app->make(RegistryCacheProbe::class)),
                    new EventLagCheck($app->make(EventLogProbe::class), $app->make(Clock::class)),
                    new ParkedAggregatesCheck($app->make(EventLogProbe::class)),
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
