<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Events\Infrastructure\EventReader;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Commits events to the event log on Postgres for the runner's tests, and waits until they are
 * below the transaction horizon. The horizon is the oldest transaction open on the whole server,
 * so another checkout's suite can hold it back for a moment.
 */
final readonly class CommittedEvents
{
    public const string CHANGESET = '01960000-0000-7000-8000-000000000054';

    public function __construct(private Clock $clock) {}

    /**
     * Writes the events in one transaction on the connection, the default when null, and commits.
     *
     * @param  list<Event>  $events
     * @return list<EventPosition>
     */
    public function write(EventStream $stream, array $events, ?Connection $on = null): array
    {
        $db = $on ?? DB::connection();
        $db->beginTransaction();
        $positions = $this->writeOpen($stream, $events, $db);
        $db->commit();

        return $positions;
    }

    /**
     * Writes the events in the connection's open transaction, which the caller commits.
     *
     * @param  list<Event>  $events
     * @return list<EventPosition>
     */
    public function writeOpen(EventStream $stream, array $events, Connection $on): array
    {
        return new EventWriter(app('db'), $this->clock, $on->getName())->write(ChangesetId::fromString(self::CHANGESET), $stream, $events);
    }

    /**
     * Commits the events and returns them as the log stores them, once they are below the horizon.
     *
     * @param  list<Event>  $events
     * @return list<StoredEvent>
     */
    public function commit(EventStream $stream, array $events): array
    {
        return $this->belowHorizon($stream, $this->write($stream, $events));
    }

    /**
     * The events at the positions, read again until all are below the horizon, for at most 30 s.
     *
     * @param  list<EventPosition>  $positions
     * @return list<StoredEvent>
     */
    public function belowHorizon(EventStream $stream, array $positions): array
    {
        $deadline = microtime(true) + 30;

        do {
            $found = [];

            foreach (new EventReader(app('db'))->after($stream, EventPosition::start(), EventReader::MAX_LIMIT) as $event) {
                foreach ($positions as $index => $position) {
                    if ($event->position->equals($position)) {
                        $found[$index] = $event;
                    }
                }
            }

            if (count($found) === count($positions)) {
                ksort($found);

                return array_values($found);
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError(sprintf('Only %d of %d events were below the transaction horizon after 30 seconds.', count($found), count($positions)));
    }
}
