<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionTransactionOpen;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\CounterReset;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What every SubscriptionLog does, run against PostgresSubscriptionLog and FakeSubscriptionLog, so
 * the fake the runner's action tests use cannot drift from the log the runner reads (GUARDRAILS 9).
 */
trait SubscriptionLogBehaviour
{
    /**
     * The implementation under test, writing its times at the clock.
     */
    abstract protected function subscriptionLog(FakeClock $clock): SubscriptionLog;

    /**
     * Commits the events in one transaction and returns them as the log stores them, once they are
     * below the transaction horizon.
     *
     * @param  list<Event>  $events
     * @return list<StoredEvent>
     */
    abstract protected function commitEvents(EventStream $stream, array $events): array;

    /**
     * Makes another runner hold the subscription's lock until freeLock().
     */
    abstract protected function holdLock(SubscriptionName $subscription): void;

    abstract protected function freeLock(): void;

    /**
     * The actor of the access context the log's open batch runs under, as a subscriber on the
     * batch's connection reads it; null when none is set.
     */
    abstract protected function actorInBatch(SubscriptionLog $log): ?string;

    #[Test]
    public function it_runs_a_batch_under_the_access_context_it_is_given(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        $name = new SubscriptionName('test.context');
        $context = $this->batchContext();
        $seen = null;

        $log->transaction($name, $context, function () use ($log, &$seen): BatchProgress {
            $seen = $this->actorInBatch($log);

            return new BatchProgress;
        });

        $principal = $context->principal;
        Assert::assertInstanceOf(ActorPrincipal::class, $principal);
        Assert::assertSame($principal->actor->toString(), $seen);
        Assert::assertNull($this->actorInBatch($log));
    }

    #[Test]
    public function it_moves_a_cursor_per_stream_forward_only(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00Z'));
        $log = $this->subscriptionLog($clock);
        $name = new SubscriptionName('test.cursor');
        $first = new EventPosition(900, 7);
        $later = new EventPosition(901, 3);

        Assert::assertTrue($log->cursor($name, EventStream::Interactive)->equals(EventPosition::start()));

        $this->inBatch($log, $name, static function () use ($log, $name, $first, $later): void {
            $log->advance($name, EventStream::Interactive, $later);
            $log->advance($name, EventStream::Interactive, $first);
            $log->advance($name, EventStream::Bulk, $first);
        });

        Assert::assertTrue($log->cursor($name, EventStream::Interactive)->equals($later));
        Assert::assertTrue($log->cursor($name, EventStream::Bulk)->equals($first));
        Assert::assertTrue($log->cursor(new SubscriptionName('test.other'), EventStream::Interactive)->equals(EventPosition::start()));
    }

    #[Test]
    public function it_reads_the_events_after_a_cursor_in_order_and_in_pages(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        [$first, $second] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('a', 1), CounterRaised::of('b', 1)]);
        [$third] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('a', 2)]);

        $page = $log->after(EventStream::Interactive, EventPosition::start(), 2);

        Assert::assertSame([$first->position->eventId, $second->position->eventId], array_map(static fn (StoredEvent $event): int => $event->position->eventId, $page));
        Assert::assertEquals([$third], $log->after(EventStream::Interactive, $second->position, 10));
        Assert::assertSame([], $log->after(EventStream::Bulk, EventPosition::start(), 10));
    }

    #[Test]
    public function it_parks_an_aggregate_once_and_finds_it_among_others(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00.250000Z'));
        $log = $this->subscriptionLog($clock);
        $name = new SubscriptionName('test.park');
        [$bad, $again] = $this->commitEvents(EventStream::Bulk, [CounterRaised::of('bad', 1), CounterRaised::of('bad', 2)]);

        $this->inBatch($log, $name, static function () use ($log, $name, $bad, $again): void {
            $log->park($name, $bad, 3);
            $log->park($name, $again, 9);
        });

        $parked = $log->parked($name);

        Assert::assertCount(1, $parked);
        Assert::assertSame('counter:bad', $parked[0]->aggregate->toString());
        Assert::assertSame(EventStream::Bulk, $parked[0]->stream);
        Assert::assertTrue($parked[0]->position->equals($bad->position));
        Assert::assertSame(3, $parked[0]->attempts);
        Assert::assertEquals(new DateTimeImmutable('2026-04-01T08:00:00.250000Z'), $parked[0]->parkedAt);
        Assert::assertNull($parked[0]->releasedAt);
        Assert::assertEquals(
            [AggregateKey::fromString('counter:bad')],
            $log->parkedAmong($name, [AggregateKey::fromString('counter:good'), AggregateKey::fromString('counter:bad')]),
        );
        Assert::assertSame([], $log->parkedAmong(new SubscriptionName('test.other'), [AggregateKey::fromString('counter:bad')]));
        Assert::assertSame([], $log->parkedAmong($name, []));

        $this->inBatch($log, $name, static function () use ($log, $name): void {
            $log->unpark($name, AggregateKey::fromString('counter:bad'));
        });

        Assert::assertSame([], $log->parked($name));
    }

    #[Test]
    public function it_releases_a_parked_aggregate_and_parks_it_again(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00Z'));
        $log = $this->subscriptionLog($clock);
        $name = new SubscriptionName('test.release');
        [$bad, $worse] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('bad', 1), CounterRaised::of('worse', 1)]);
        $this->inBatch($log, $name, static function () use ($log, $name, $bad, $worse): void {
            $log->park($name, $bad, 3);
            $log->park($name, $worse, 3);
        });

        $clock->set(new DateTimeImmutable('2026-04-01T09:00:00Z'));
        $released = $log->release($name, AggregateKey::fromString('counter:bad'));
        $clock->set(new DateTimeImmutable('2026-04-01T10:00:00Z'));
        $again = $log->release($name, AggregateKey::fromString('counter:bad'));

        Assert::assertInstanceOf(ParkedAggregate::class, $released);
        Assert::assertEquals(new DateTimeImmutable('2026-04-01T09:00:00Z'), $released->releasedAt);
        Assert::assertEquals($released->releasedAt, $again?->releasedAt);
        Assert::assertNull($log->release($name, AggregateKey::fromString('counter:good')));
        Assert::assertSame(['counter:bad'], array_map(static fn (ParkedAggregate $parked): string => $parked->aggregate->toString(), $log->released($name, 10)));

        $this->inBatch($log, $name, static function () use ($log, $name): void {
            $log->repark($name, AggregateKey::fromString('counter:bad'), 2);
        });

        Assert::assertSame([], $log->released($name, 10));
        Assert::assertSame(
            ['counter:bad 5', 'counter:worse 3'],
            array_map(static fn (ParkedAggregate $parked): string => $parked->aggregate->toString().' '.$parked->attempts, $log->parked(null)),
        );
    }

    #[Test]
    public function it_finds_the_newest_event_of_an_aggregate_the_subscription_has_passed(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        $name = new SubscriptionName('test.newest');
        [$v1, $other] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('bad', 1), CounterRaised::of('good', 1)]);
        [$v3] = $this->commitEvents(EventStream::Bulk, [CounterRaised::of('bad', 3)]);
        [$v2, $reset] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('bad', 2), CounterReset::of('bad', 4)]);
        [$v5] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('bad', 5)]);
        $types = [CounterRaised::type()];
        $bad = AggregateKey::fromString('counter:bad');

        Assert::assertNull($log->newest($name, $bad, $types));

        $this->inBatch($log, $name, static function () use ($log, $name, $reset): void {
            $log->advance($name, EventStream::Interactive, $reset->position);
        });

        Assert::assertEquals($v2, $log->newest($name, $bad, $types));

        $this->inBatch($log, $name, static function () use ($log, $name, $v3): void {
            $log->advance($name, EventStream::Bulk, $v3->position);
        });

        Assert::assertEquals($v3, $log->newest($name, $bad, $types));
        Assert::assertEquals($reset, $log->newest($name, $bad, [CounterReset::type()]));
        Assert::assertNull($log->newest($name, $bad, []));
        Assert::assertNull($log->newest($name, AggregateKey::fromString('counter:ghost'), $types));
        Assert::assertTrue($v5->position->isAfter($reset->position));
        Assert::assertTrue($other->position->isAfter($v1->position));
    }

    #[Test]
    public function it_commits_a_batch_and_rolls_back_one_that_throws(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        $name = new SubscriptionName('test.rollback');
        [$event] = $this->commitEvents(EventStream::Interactive, [CounterRaised::of('bad', 1)]);

        $progress = $log->transaction($name, $this->batchContext(), static function () use ($log, $name, $event): BatchProgress {
            $log->advance($name, EventStream::Interactive, $event->position);

            return new BatchProgress(handled: 1, moved: true);
        });

        Assert::assertEquals(new BatchProgress(handled: 1, moved: true), $progress);

        try {
            $log->transaction($name, $this->batchContext(), static function () use ($log, $name, $event): BatchProgress {
                $log->advance($name, EventStream::Interactive, new EventPosition($event->position->xid + 1, $event->position->eventId + 1));
                $log->park($name, $event, 1);

                throw new RuntimeException('The subscriber failed.');
            });
            Assert::fail('The exception goes on.');
        } catch (RuntimeException $failed) {
            Assert::assertSame('The subscriber failed.', $failed->getMessage());
        }

        Assert::assertTrue($log->cursor($name, EventStream::Interactive)->equals($event->position));
        Assert::assertSame([], $log->parked($name));
    }

    #[Test]
    public function it_passes_a_batch_whose_lock_another_runner_holds(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        $name = new SubscriptionName('test.busy');
        $ran = false;
        $this->holdLock($name);

        try {
            $progress = $log->transaction($name, $this->batchContext(), static function () use (&$ran): BatchProgress {
                $ran = true;

                return new BatchProgress;
            });
            $other = $log->transaction(new SubscriptionName('test.free'), $this->batchContext(), static fn (): BatchProgress => new BatchProgress(moved: true));
        } finally {
            $this->freeLock();
        }

        Assert::assertNull($progress);
        Assert::assertFalse($ran);
        Assert::assertEquals(new BatchProgress(moved: true), $other);
        Assert::assertEquals(new BatchProgress, $log->transaction($name, $this->batchContext(), static fn (): BatchProgress => new BatchProgress));
    }

    #[Test]
    public function it_never_nests_a_batch(): void
    {
        $log = $this->subscriptionLog(new FakeClock);
        $name = new SubscriptionName('test.nested');

        try {
            $log->transaction($name, $this->batchContext(), static fn (): BatchProgress => $log->transaction(new SubscriptionName('test.inner'), AccessContext::anonymous(), static fn (): BatchProgress => new BatchProgress) ?? new BatchProgress);
            Assert::fail('A batch inside a batch is refused.');
        } catch (SubscriptionTransactionOpen $open) {
            Assert::assertStringContainsString('begins its own transaction', $open->getMessage());
        }

        Assert::assertEquals(new BatchProgress, $log->transaction($name, $this->batchContext(), static fn (): BatchProgress => new BatchProgress));
    }

    /**
     * @param  Closure(): void  $work
     */
    private function inBatch(SubscriptionLog $log, SubscriptionName $name, Closure $work): void
    {
        $log->transaction($name, $this->batchContext(), static function () use ($work): BatchProgress {
            $work();

            return new BatchProgress;
        });
    }

    /**
     * The access context of a service actor without grants, as the runner compiles it.
     */
    private function batchContext(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000c0de'), [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()),
            [],
            ClassificationAccess::Public,
        );
    }
}
