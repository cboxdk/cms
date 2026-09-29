<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\CommandTransactionBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Override;

/**
 * CommandTransactionBehaviour against the container's CommandTransaction, ConnectionCommandTransaction
 * on the default connection, as the app role on real Postgres, with the Postgres idempotency store
 * on the same connection.
 */
final class ConnectionCommandTransactionBehaviourTest extends TestCase
{
    use CommandTransactionBehaviour;
    use RealPostgres;

    private ?FakeClock $clock = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(PartitionFixtures::class)->coverClock($this->fakeClock(), new DateInterval('P1D'));
    }

    #[Override]
    protected function commandTransaction(): CommandTransaction
    {
        return app(CommandTransaction::class);
    }

    #[Override]
    protected function keys(): IdempotencyStore
    {
        return new PostgresIdempotencyStore(app(DatabaseManager::class), $this->fakeClock());
    }

    #[Override]
    protected function clock(): Clock
    {
        return $this->fakeClock();
    }

    #[Override]
    protected function openOutside(): void
    {
        app(DatabaseManager::class)->connection()->beginTransaction();
    }

    #[Override]
    protected function closeOutside(): void
    {
        app(DatabaseManager::class)->connection()->rollBack();
    }

    private function fakeClock(): FakeClock
    {
        return $this->clock ??= new FakeClock;
    }
}
