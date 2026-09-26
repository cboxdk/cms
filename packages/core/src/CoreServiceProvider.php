<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the core package in a Laravel application. Loaded through package discovery.
 *
 * Binds each contract to the implementation configured in `cms.contracts` (GUARDRAILS 2.3), loads
 * the core's migrations, binds partition maintenance to the Postgres partition manager, and
 * schedules it.
 */
#[Internal]
final class CoreServiceProvider extends ServiceProvider
{
    /** The command the cli package registers for partition maintenance. */
    public const string PARTITIONS_COMMAND = 'cms:partitions:maintain';

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
}
