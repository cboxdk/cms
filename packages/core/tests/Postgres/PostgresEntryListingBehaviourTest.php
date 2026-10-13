<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryListing;
use Cbox\Cms\Core\Tests\Entries\EntryListingBehaviour;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * PostgresEntryListing against EntryListingBehaviour, as the app role in a transaction with the
 * reader's context set over the EntryListingWorld, written as the superuser, with the workbench's
 * type catalog.
 */
final class PostgresEntryListingBehaviourTest extends TestCase
{
    use EntryListingBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(PartitionFixtures::class)->coverClock(new FakeClock(new DateTimeImmutable(EntryListingWorld::CREATED_AT)), new DateInterval('P1D'));
        EntryListingWorld::seed();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->beginTransaction();

        try {
            app(ActorContext::class)->set(EntryListingWorld::context($reader));
            $read(new PostgresEntryListing(app(ConnectionResolverInterface::class), app(TypeCatalog::class), app(TypeEditors::class)));
        } finally {
            $connection->rollBack();
        }
    }
}
