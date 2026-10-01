<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Adapter\SubscriptionLock;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Tests\Subscriptions\CommittedEvents;
use Cbox\Cms\Core\Tests\Subscriptions\SubscriptionLogBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Override;

/**
 * SubscriptionLogBehaviour against the log on Postgres, as the app role on the default connection,
 * with another runner's lock held on a connection of its own.
 */
final class PostgresSubscriptionLogBehaviourTest extends TestCase
{
    use RealPostgres;
    use SubscriptionLogBehaviour;

    private ?Connection $holder = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(PartitionFixtures::class)->coverClock($this->eventClock(), new DateInterval('P1D'));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->freeLock();
        app(IndependentConnections::class)->closeAll();

        parent::tearDown();
    }

    #[Override]
    protected function subscriptionLog(FakeClock $clock): SubscriptionLog
    {
        return new PostgresSubscriptionLog(app('db'), $clock);
    }

    #[Override]
    protected function commitEvents(EventStream $stream, array $events): array
    {
        return new CommittedEvents($this->eventClock())->commit($stream, $events);
    }

    #[Override]
    protected function holdLock(SubscriptionName $subscription): void
    {
        [$this->holder] = app(IndependentConnections::class)->open(1);
        $this->holder->beginTransaction();
        $this->holder->select('select pg_advisory_xact_lock(?)', [SubscriptionLock::of($subscription)]);
    }

    #[Override]
    protected function freeLock(): void
    {
        if ($this->holder instanceof Connection && $this->holder->transactionLevel() > 0) {
            $this->holder->rollBack();
        }

        $this->holder = null;
    }

    #[Override]
    protected function actorInBatch(SubscriptionLog $log): ?string
    {
        $row = app('db')->selectOne("select nullif(current_setting('cbox_cms.actor', true), '') as actor");
        $actor = is_object($row) && property_exists($row, 'actor') ? $row->actor : null;

        return is_string($actor) ? $actor : null;
    }

    private function eventClock(): FakeClock
    {
        return new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00Z'));
    }
}
