<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Builds the test schema once per PHP process, as the owner role.
 *
 * Migrations are DDL, and the app role has none (PRD 4.2), so they run on the owner connection.
 * `migrate:fresh` drops what an earlier run left and runs every registered migration, like
 * Laravel's RefreshDatabase does once per process, but without the wrapping transaction.
 */
#[Experimental]
final class OwnerMigrations
{
    private static bool $migrated = false;

    public static function ensure(Kernel $artisan, string $ownerConnection): void
    {
        if (self::$migrated) {
            return;
        }

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
