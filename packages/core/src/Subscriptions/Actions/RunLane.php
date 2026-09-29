<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\Parking;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Subscriptions\Domain\LaneState;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Subscriptions\Domain\RunnerStop;
use Cbox\Cms\Core\Subscriptions\Domain\ServiceIdentityRefused;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriberFailed;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Closure;
use Throwable;

/**
 * The event runner of one lane (PRD 7.4 to 7.8): it hands the events of the event log to the
 * lane's subscribers, as the subscribers' service identity.
 *
 * Before each round it reads the service actor that cbox-cms.events.runner.service_actor names
 * through the ActorDirectory and refuses to go on when it is missing, not a service actor or not
 * active (ServiceIdentityRefused), so a deactivation stops the runner within a round (PRD 5.16).
 *
 * A round gives each subscription of the lane, in registry order, one batch of its released
 * aggregates and then one batch per stream. A batch is one transaction of the SubscriptionLog,
 * which holds the subscription's lock, so a second runner of the lane passes the subscription
 * instead of handling it twice. In it the runner reads the events after the subscription's cursor
 * below the transaction horizon, in (xid, event_id) order (PRD 7.4), and for each:
 *
 * - passes it when the subscription does not receive its type, or its aggregate is parked for the
 *   subscription: a parked aggregate's later events are parked with it (PRD 7.8);
 * - parks its aggregate when the event failed maxAttempts tries in a row, and passes it;
 * - hands it to the subscriber otherwise, with a Delivery that names the service actor and the try.
 *
 * It then moves the cursor to the last event it passed or handled and commits: the cursor commits
 * with what the subscriber wrote on the connection (PRD 7.4). A batch reads at most batchSize
 * events and stops handing them once it has run batchBudgetMs, so its transaction stays under 2
 * seconds; the next batch goes on from the cursor.
 *
 * When the subscriber throws, the batch rolls back, and with it what the subscriber and the events
 * before wrote. The runner then hands those events again at once in a batch that ends before the
 * failed one, and tries the failed one again after an exponential backoff, backoffBaseMs doubled
 * per failed try up to backoffMaxMs (PRD 7.7). The batches of the lane's other queues go on in the
 * meantime. After maxAttempts failed tries the next batch parks the aggregate and passes the event,
 * so every other aggregate keeps flowing.
 *
 * An operator releases a parked aggregate once its fault is fixed (ReleaseParked). The release
 * batch then hands the subscriber, once, the newest event of the aggregate that the subscription
 * has passed, the aggregate's current version, with Delivery::$release set, and removes the
 * parking in the same transaction. The events after the cursor come later as usual. A release that
 * fails maxAttempts tries parks the aggregate again. An aggregate the log no longer has an event of
 * the subscription's types for, because retention dropped them, is unparked and reported.
 *
 * With LaneRun::$untilIdle the run ends after a round that did nothing while no try waits;
 * otherwise it runs until the RunnerStop asks it to stop, waiting idleSleepMs, or less until a try
 * is due, after a round that did nothing.
 */
#[Experimental]
final readonly class RunLane
{
    private const string RELEASES = 'releases';

    public function __construct(
        private SubscriptionLog $log,
        private LaneSubscribers $subscribers,
        private ActorDirectory $actors,
        private RunnerSettings $settings,
        private Pacing $pacing,
    ) {}

    /**
     * @throws ServiceIdentityRefused when the service actor is not configured, missing, not a service actor or not active
     */
    public function run(LaneRun $run, RunnerStop $stop): LaneReport
    {
        $actor = $this->identity();
        $bindings = $this->subscribers->in($run->lane);
        $state = new LaneState;

        while (! $stop->requested()) {
            $actor = $this->identity();
            $moved = false;

            foreach ($bindings as $binding) {
                $moved = $this->releases($binding, $actor, $state) || $moved;

                foreach (EventStream::cases() as $stream) {
                    $moved = $this->events($binding, $stream, $actor, $state) || $moved;
                }
            }

            if ($moved) {
                continue;
            }

            $due = $state->nextDueIn($this->pacing->milliseconds());

            if ($due === null && $run->untilIdle) {
                break;
            }

            if ($due !== 0) {
                $this->pacing->sleep(min($due ?? $this->settings->idleSleepMs, $this->settings->idleSleepMs));
            }
        }

        return $state->report($run->lane, $actor);
    }

    /**
     * The service actor the subscribers run as.
     *
     * @throws ServiceIdentityRefused
     */
    private function identity(): ActorId
    {
        $id = $this->settings->serviceActor ?? throw ServiceIdentityRefused::notConfigured();
        $actor = $this->actors->find($id) ?? throw ServiceIdentityRefused::unknown($id);

        if ($actor->class !== ActorClass::Service) {
            throw ServiceIdentityRefused::notAService($id, $actor->class);
        }

        if (! $actor->isActive()) {
            throw ServiceIdentityRefused::notActive($id, $actor->state);
        }

        return $id;
    }

    /**
     * One batch of the subscription's events in the stream; whether it did anything or has units
     * to hand again at once.
     */
    private function events(SubscriberBinding $binding, EventStream $stream, ActorId $actor, LaneState $state): bool
    {
        $queue = $binding->entry->name->value.'|'.$stream->value;

        return $this->batch($queue, $binding, $state, fn (): BatchProgress => $this->handleEvents(
            $binding,
            $stream,
            $actor,
            $state,
            $state->limit($queue, $this->settings->batchSize),
        ));
    }

    /**
     * One batch of the subscription's released aggregates.
     */
    private function releases(SubscriberBinding $binding, ActorId $actor, LaneState $state): bool
    {
        $queue = $binding->entry->name->value.'|'.self::RELEASES;

        return $this->batch($queue, $binding, $state, fn (): BatchProgress => $this->handleReleases(
            $binding,
            $actor,
            $state,
            $state->limit($queue, $this->settings->batchSize),
        ));
    }

    /**
     * Runs a batch of the queue in the log's transaction when the queue is due, and records what
     * it did or how it failed.
     *
     * @param  Closure(): BatchProgress  $work
     */
    private function batch(string $queue, SubscriberBinding $binding, LaneState $state, Closure $work): bool
    {
        if (! $state->due($queue, $this->pacing->milliseconds())) {
            return false;
        }

        try {
            $progress = $this->log->transaction($binding->entry->name, $work);
        } catch (SubscriberFailed $failed) {
            $failures = $state->failures($failed->unit) + 1;
            $wait = $failures >= $this->settings->maxAttempts ? 0 : $this->settings->backoff($failures);
            $state->failed($queue, $failed->unit, $failed->index, $this->pacing->milliseconds() + $wait);

            return $failed->index > 0 || $wait === 0;
        }

        if (! $progress instanceof BatchProgress) {
            $state->busy();

            return false;
        }

        $state->committed($queue, $progress);

        return $progress->moved;
    }

    private function handleEvents(SubscriberBinding $binding, EventStream $stream, ActorId $actor, LaneState $state, int $limit): BatchProgress
    {
        $name = $binding->entry->name;
        $cursor = $this->log->cursor($name, $stream);
        $events = $this->log->after($stream, $cursor, $limit);

        if ($events === []) {
            return new BatchProgress;
        }

        $parked = $this->parkedAmong($binding, $events);
        $started = $this->pacing->milliseconds();
        $last = $cursor;
        $handled = 0;
        $passed = 0;
        $passedParked = 0;
        $parkings = [];

        foreach ($events as $index => $event) {
            if ($handled > 0 && $this->pacing->milliseconds() - $started >= $this->settings->batchBudgetMs) {
                break;
            }

            $last = $event->position;

            if (! $binding->receives($event->type)) {
                $passed++;

                continue;
            }

            $aggregate = AggregateKey::of($event->aggregate);

            if (isset($parked[$aggregate->toString()])) {
                $passedParked++;

                continue;
            }

            $unit = $this->eventUnit($binding, $event);
            $failures = $state->failures($unit);

            if ($failures >= $this->settings->maxAttempts) {
                $this->log->park($name, $event, $failures);
                $parked[$aggregate->toString()] = true;
                $parkings[] = new Parking($name, $aggregate, $failures);
                $state->forget($unit);

                continue;
            }

            $this->deliver($binding, $event, new Delivery($actor, $failures + 1), $unit, $index);
            $state->forget($unit);
            $handled++;
        }

        if ($last->isAfter($cursor)) {
            $this->log->advance($name, $stream, $last);
        }

        return new BatchProgress($handled, $passed, $passedParked, $parkings, moved: $last->isAfter($cursor) || $parkings !== []);
    }

    private function handleReleases(SubscriberBinding $binding, ActorId $actor, LaneState $state, int $limit): BatchProgress
    {
        $name = $binding->entry->name;
        $released = $this->log->released($name, $limit);
        $started = $this->pacing->milliseconds();
        $handled = 0;
        $reparked = [];
        $withoutEvent = [];

        foreach ($released as $index => $parked) {
            if ($handled > 0 && $this->pacing->milliseconds() - $started >= $this->settings->batchBudgetMs) {
                break;
            }

            $unit = $name->value.'|release|'.$parked->aggregate->toString();
            $failures = $state->failures($unit);

            if ($failures >= $this->settings->maxAttempts) {
                $this->log->repark($name, $parked->aggregate, $failures);
                $reparked[] = new Parking($name, $parked->aggregate, $parked->attempts + $failures);
                $state->forget($unit);

                continue;
            }

            $event = $this->log->newest($name, $parked->aggregate, $binding->types());

            if ($event instanceof StoredEvent) {
                $this->deliver($binding, $event, new Delivery($actor, $failures + 1, release: true), $unit, $index);
                $handled++;
            } else {
                $withoutEvent[] = $parked->aggregate;
            }

            $this->log->unpark($name, $parked->aggregate);
            $state->forget($unit);
        }

        return new BatchProgress(
            parked: $reparked,
            released: $handled,
            releasedWithoutEvent: $withoutEvent,
            moved: $handled > 0 || $reparked !== [] || $withoutEvent !== [],
        );
    }

    /**
     * Those of the events' aggregates that are parked for the subscription, by key.
     *
     * @param  list<StoredEvent>  $events
     * @return array<string, true>
     */
    private function parkedAmong(SubscriberBinding $binding, array $events): array
    {
        $aggregates = [];

        foreach ($events as $event) {
            if ($binding->receives($event->type)) {
                $aggregate = AggregateKey::of($event->aggregate);
                $aggregates[$aggregate->toString()] = $aggregate;
            }
        }

        $parked = [];

        foreach ($this->log->parkedAmong($binding->entry->name, array_values($aggregates)) as $aggregate) {
            $parked[$aggregate->toString()] = true;
        }

        return $parked;
    }

    /**
     * @throws SubscriberFailed when the subscriber throws
     */
    private function deliver(SubscriberBinding $binding, StoredEvent $event, Delivery $delivery, string $unit, int $index): void
    {
        try {
            $binding->subscriber->handle($event, $delivery);
        } catch (Throwable $cause) {
            throw new SubscriberFailed($unit, $index, $cause);
        }
    }

    private function eventUnit(SubscriberBinding $binding, StoredEvent $event): string
    {
        return sprintf('%s|%s|%s', $binding->entry->name->value, $event->stream->value, $this->position($event->position));
    }

    private function position(EventPosition $position): string
    {
        return $position->xid.'.'.$position->eventId;
    }
}
