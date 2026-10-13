<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryPreviews;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Core\Tests\Entries\EntryPreviewsBehaviour;
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
 * PostgresEntryPreviews against EntryPreviewsBehaviour, as the app role in a transaction with the
 * reader's context set over the EntryListingWorld, written as the superuser, with the workbench's
 * type catalog.
 */
final class PostgresEntryPreviewsBehaviourTest extends TestCase
{
    use EntryPreviewsBehaviour;
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
    protected function previewAs(string $reader, Closure $read): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->beginTransaction();

        try {
            app(ActorContext::class)->set(EntryListingWorld::context($reader));
            $read(new PostgresEntryPreviews(app(ConnectionResolverInterface::class), app(TypeCatalog::class)));
        } finally {
            $connection->rollBack();
        }
    }
}
