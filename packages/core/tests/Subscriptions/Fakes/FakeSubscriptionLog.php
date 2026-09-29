<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fakes;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionTransactionOpen;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Closure;
use LogicException;
use Override;
use Throwable;

/**
 * The event log in memory for the runner's action tests (GUARDRAILS 9). Every recorded event is
 * below the horizon at once; each record() is a transaction of its own with the next xid, and the
 * events get the next event_ids. A transaction keeps a copy of the cursors and parkings and puts it
 * back when the work throws, so a failed batch leaves nothing, as a rollback in Postgres does.
 * hold() makes a subscription's lock taken by another runner.
 */
final class FakeSubscriptionLog implements SubscriptionLog
{
    /** @var array<string, list<StoredEvent>> by stream */
    private array $events = [];

    /** @var array<string, EventPosition> by subscription and stream */
    private array $cursors = [];

    /** @var array<string, ParkedAggregate> by subscription and aggregate */
    private array $parkings = [];

    /** @var array<string, true> the subscriptions whose lock another runner holds */
    private array $held = [];

    private bool $open = false;

    private int $xid = 1000;

    private int $eventId = 0;

    private int $transactions = 0;

    public function __construct(private readonly Clock $clock = new FakeClock) {}

    /**
     * Commits the events in a transaction of their own, in the order given.
     *
     * @param  list<Event>  $events
     * @return list<StoredEvent>
     */
    public function record(EventStream $stream, array $events): array
    {
        $this->xid++;
        $stored = [];

        foreach ($events as $event) {
            $stored[] = new StoredEvent(
                new EventPosition($this->xid, ++$this->eventId),
                $this->clock->now(),
                ChangesetId::fromString('01960000-0000-7000-8000-000000000001'),
                $stream,
                1,
                $event->aggregate(),
                $event::type(),
                $event->payload()->data(),
            );
        }

        $this->events[$stream->value] = [...($this->events[$stream->value] ?? []), ...$stored];

        return $stored;
    }

    public function hold(SubscriptionName $subscription): void
    {
        $this->held[$subscription->value] = true;
    }

    public function free(SubscriptionName $subscription): void
    {
        unset($this->held[$subscription->value]);
    }

    /**
     * The transactions that ran their work.
     */
    public function transactions(): int
    {
        return $this->transactions;
    }

    #[Override]
    public function transaction(SubscriptionName $subscription, Closure $work): ?BatchProgress
    {
        if ($this->open) {
            throw SubscriptionTransactionOpen::onConnection('fake');
        }

        if (isset($this->held[$subscription->value])) {
            return null;
        }

        $cursors = $this->cursors;
        $parkings = $this->parkings;
        $this->open = true;
        $this->transactions++;

        try {
            return $work();
        } catch (Throwable $exception) {
            $this->cursors = $cursors;
            $this->parkings = $parkings;

            throw $exception;
        } finally {
            $this->open = false;
        }
    }

    #[Override]
    public function cursor(SubscriptionName $subscription, EventStream $stream): EventPosition
    {
        return $this->cursors[$subscription->value.'|'.$stream->value] ?? EventPosition::start();
    }

    #[Override]
    public function after(EventStream $stream, EventPosition $cursor, int $limit): array
    {
        $after = array_values(array_filter(
            $this->events[$stream->value] ?? [],
            static fn (StoredEvent $event): bool => $event->position->isAfter($cursor),
        ));

        return array_slice($after, 0, $limit);
    }

    #[Override]
    public function parkedAmong(SubscriptionName $subscription, array $aggregates): array
    {
        $parked = array_values(array_filter(
            $aggregates,
            fn (AggregateKey $aggregate): bool => isset($this->parkings[$this->key($subscription, $aggregate)]),
        ));

        usort($parked, static fn (AggregateKey $a, AggregateKey $b): int => [$a->type->value, $a->id->value] <=> [$b->type->value, $b->id->value]);

        return $parked;
    }

    #[Override]
    public function advance(SubscriptionName $subscription, EventStream $stream, EventPosition $to): void
    {
        $this->inTransaction();

        if ($to->isAfter($this->cursor($subscription, $stream))) {
            $this->cursors[$subscription->value.'|'.$stream->value] = $to;
        }
    }

    #[Override]
    public function park(SubscriptionName $subscription, StoredEvent $event, int $attempts): void
    {
        $this->inTransaction();
        $aggregate = AggregateKey::of($event->aggregate);

        $this->parkings[$this->key($subscription, $aggregate)] ??= new ParkedAggregate($subscription, $aggregate, $event->stream, $event->position, $attempts, $this->clock->now(), null);
    }

    #[Override]
    public function released(SubscriptionName $subscription, int $limit): array
    {
        $released = array_values(array_filter(
            $this->parked($subscription),
            static fn (ParkedAggregate $parked): bool => $parked->isReleased(),
        ));

        return array_slice($released, 0, $limit);
    }

    #[Override]
    public function newest(SubscriptionName $subscription, AggregateKey $aggregate, array $types): ?StoredEvent
    {
        $newest = null;

        foreach ($this->events as $events) {
            foreach ($events as $event) {
                if (! AggregateKey::of($event->aggregate)->equals($aggregate)
                    || ! array_any($types, static fn (EventType $type): bool => $type->equals($event->type))
                    || $event->position->isAfter($this->cursor($subscription, $event->stream))) {
                    continue;
                }

                if (! $newest instanceof StoredEvent || [$event->aggregate->version, $event->position->xid, $event->position->eventId] > [$newest->aggregate->version, $newest->position->xid, $newest->position->eventId]) {
                    $newest = $event;
                }
            }
        }

        return $newest;
    }

    #[Override]
    public function unpark(SubscriptionName $subscription, AggregateKey $aggregate): void
    {
        $this->inTransaction();
        unset($this->parkings[$this->key($subscription, $aggregate)]);
    }

    #[Override]
    public function repark(SubscriptionName $subscription, AggregateKey $aggregate, int $attempts): void
    {
        $this->inTransaction();
        $key = $this->key($subscription, $aggregate);
        $parked = $this->parkings[$key] ?? null;

        if ($parked instanceof ParkedAggregate) {
            $this->parkings[$key] = new ParkedAggregate($parked->subscription, $parked->aggregate, $parked->stream, $parked->position, $parked->attempts + $attempts, $parked->parkedAt, null);
        }
    }

    #[Override]
    public function release(SubscriptionName $subscription, AggregateKey $aggregate): ?ParkedAggregate
    {
        $key = $this->key($subscription, $aggregate);
        $parked = $this->parkings[$key] ?? null;

        if (! $parked instanceof ParkedAggregate) {
            return null;
        }

        return $this->parkings[$key] = new ParkedAggregate($parked->subscription, $parked->aggregate, $parked->stream, $parked->position, $parked->attempts, $parked->parkedAt, $parked->releasedAt ?? $this->clock->now());
    }

    #[Override]
    public function parked(?SubscriptionName $subscription): array
    {
        $parked = array_values(array_filter(
            $this->parkings,
            static fn (ParkedAggregate $parked): bool => ! $subscription instanceof SubscriptionName || $parked->subscription->equals($subscription),
        ));

        usort($parked, static fn (ParkedAggregate $a, ParkedAggregate $b): int => [$a->subscription->value, $a->parkedAt, $a->aggregate->type->value, $a->aggregate->id->value]
            <=> [$b->subscription->value, $b->parkedAt, $b->aggregate->type->value, $b->aggregate->id->value]);

        return $parked;
    }

    private function key(SubscriptionName $subscription, AggregateKey $aggregate): string
    {
        return $subscription->value.'|'.$aggregate->toString();
    }

    private function inTransaction(): void
    {
        if (! $this->open) {
            throw new LogicException('The fake log writes cursors and parkings only inside transaction().');
        }
    }
}
