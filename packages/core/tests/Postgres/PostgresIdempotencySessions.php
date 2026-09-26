<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\DatabaseManager;

/**
 * The shared IdempotencyStoreContract suite's harness for the Postgres store: each session is an
 * independent connection as the app role, with its own backend, and a PostgresIdempotencyStore
 * bound to it. The partitions from the clock's date to 8 days later exist before the first
 * session, because the suite completes claims after moving the clock past the 7-day expiry.
 */
final readonly class PostgresIdempotencySessions implements IdempotencyStoreHarness
{
    private function __construct(private Clock $clock) {}

    public static function at(Clock $clock): self
    {
        $now = $clock->now();
        app(PartitionFixtures::class)->cover($now, $now->add(new DateInterval('P8D')));

        return new self($clock);
    }

    public function session(): PostgresIdempotencySession
    {
        [$connection] = app(IndependentConnections::class)->open(1);

        return new PostgresIdempotencySession(
            $connection,
            new PostgresIdempotencyStore(app(DatabaseManager::class), $this->clock, $connection->getName()),
        );
    }
}
