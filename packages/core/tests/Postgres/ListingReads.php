<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * The setup of the Postgres tests of the listing ports: the ListingWorld written as the superuser,
 * and each read in a transaction of the app role with the reader's context, set by the
 * PostgresAccessResolver from the reader's grants, rolled back after it.
 */
trait ListingReads
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        ListingWorld::seed();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    /**
     * @param  Closure(): void  $read
     */
    private function readAs(string $reader, Closure $read): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->beginTransaction();

        try {
            app(AccessResolver::class)->resolve(ListingWorld::principal($reader));
            $read();
        } finally {
            $connection->rollBack();
        }
    }
}
