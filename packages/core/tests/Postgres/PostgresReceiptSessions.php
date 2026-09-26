<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Illuminate\Database\DatabaseManager;

/**
 * The shared ReceiptStoreContract suite's harness for the Postgres store: each session is an
 * independent connection as the app role, with its own backend, and a PostgresReceiptStore bound
 * to it. The partitions for the clock's date exist before the first session.
 */
final readonly class PostgresReceiptSessions implements ReceiptStoreHarness
{
    private function __construct(private Clock $clock) {}

    public static function at(Clock $clock): self
    {
        $now = $clock->now();
        app(PartitionFixtures::class)->cover($now, $now);

        return new self($clock);
    }

    public function session(): PostgresReceiptSession
    {
        [$connection] = app(IndependentConnections::class)->open(1);

        return new PostgresReceiptSession(
            $connection,
            new PostgresReceiptStore(app(DatabaseManager::class), $this->clock, $connection->getName()),
        );
    }
}
