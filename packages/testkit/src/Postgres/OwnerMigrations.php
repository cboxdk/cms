<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\Infrastructure\PartitionSweep;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Builds the test schema once per PHP process, as the owner role.
 *
 * Migrations are DDL, and the app role has none (PRD 4.2), so they run on the owner connection.
 * `migrate:fresh` drops what an earlier run left and runs every registered migration, like
 * Laravel's RefreshDatabase does once per process, but without the wrapping transaction. The leaf
 * partitions an earlier run left go first, one statement each (PartitionSweep), so the one statement
 * of migrate:fresh never needs more locks than the server's shared lock table holds.
 */
#[Experimental]
final class OwnerMigrations
{
    private static bool $migrated = false;

    public static function ensure(Kernel $artisan, Connection $owner, string $ownerConnection): void
    {
        if (self::$migrated) {
            return;
        }

        new PartitionSweep($owner)->drop();

        $status = $artisan->call('migrate:fresh', [
            '--database' => $ownerConnection,
            '--force' => true,
        ]);

        if ($status !== 0) {
            throw new AssertionFailedError(sprintf(
                "migrate:fresh on the owner connection [%s] failed with exit code %d.\n%s",
                $ownerConnection,
                $status,
                $artisan->output(),
            ));
        }

        self::$migrated = true;
    }
}
