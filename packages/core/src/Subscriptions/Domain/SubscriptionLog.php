<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use Closure;

/**
 * The event log as a subscription's runner sees it (PRD 7.4 to 7.8): the subscription's cursor per
 * stream, the events after it, and the aggregates it parked.
 *
 * transaction() runs a batch: one transaction at READ COMMITTED on the connection the subscribers
 * write on, under the access context of the actor the subscription runs as (PRD 6.5 invariant 21),
 * set as its first statement after the isolation level, so what the subscriber reads and writes
 * there is bounded by that actor's grants and row level security, and holding the subscription's
 * lock for its length, so two runners never handle the same subscription at once. What the batch and the subscribers wrote commits together with the moved
 * cursor, or none of it does: when the work throws, the transaction rolls back and the exception
 * goes on. When another runner holds the lock, it returns null without running the work. It never
 * nests, because savepoints are forbidden (PRD 4.2). The other methods run in the caller's
 * transaction, the batch's, except release() and parked(), which an operator calls on their own.
 */
#[Internal]
interface SubscriptionLog
{
    /**
     * @param  Closure(): BatchProgress  $work
     *
     * @throws SubscriptionTransactionOpen when a transaction is already open on the connection
     * @throws SubscriberFailed when the work throws it, after the transaction rolled back
     */
    public function transaction(SubscriptionName $subscription, AccessContext $context, Closure $work): ?BatchProgress;

    /**
     * The position of the last event the subscription passed in the stream, or
     * EventPosition::start() before the first.
     */
    public function cursor(SubscriptionName $subscription, EventStream $stream): EventPosition;

    /**
     * The first $limit events of the stream after the cursor, below the transaction horizon, in
     * (xid, event_id) order, read on the primary (PRD 7.4).
     *
     * @return list<StoredEvent>
     */
    public function after(EventStream $stream, EventPosition $cursor, int $limit): array;

    /**
     * Those of the aggregates that are parked for the subscription, released or not.
     *
     * @param  list<AggregateKey>  $aggregates
     * @return list<AggregateKey>
     */
    public function parkedAmong(SubscriptionName $subscription, array $aggregates): array;

    /**
     * Moves the subscription's cursor in the stream forward to the position; a position that is
     * not after the cursor leaves it where it is.
     */
    public function advance(SubscriptionName $subscription, EventStream $stream, EventPosition $to): void;

    /**
     * Parks the event's aggregate for the subscription after $attempts failed tries, with the
     * event's position as the first parked. An aggregate already parked stays as it is.
     */
    public function park(SubscriptionName $subscription, StoredEvent $event, int $attempts): void;

    /**
     * The released aggregates of the subscription, at most $limit, oldest parked first.
     *
     * @return list<ParkedAggregate>
     */
    public function released(SubscriptionName $subscription, int $limit): array;

    /**
     * The event of the aggregate with the highest aggregate version among those of the types that
     * the subscription has passed, at or before its cursor in each stream; null when there is none.
     *
     * @param  list<EventType>  $types
     */
    public function newest(SubscriptionName $subscription, AggregateKey $aggregate, array $types): ?StoredEvent;

    /**
     * Removes the aggregate's parking for the subscription.
     */
    public function unpark(SubscriptionName $subscription, AggregateKey $aggregate): void;

    /**
     * Parks a released aggregate again after $attempts more failed tries.
     */
    public function repark(SubscriptionName $subscription, AggregateKey $aggregate, int $attempts): void;

    /**
     * Marks the parked aggregate released, so the runner handles it once at its current version;
     * null when it is not parked for the subscription. An aggregate already released stays so.
     */
    public function release(SubscriptionName $subscription, AggregateKey $aggregate): ?ParkedAggregate;

    /**
     * The parked aggregates of one subscription, or of every subscription when null, by
     * subscription and oldest parked first.
     *
     * @return list<ParkedAggregate>
     */
    public function parked(?SubscriptionName $subscription): array;
}
