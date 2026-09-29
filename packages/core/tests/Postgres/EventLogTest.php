<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Events\Infrastructure\EventReader;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The event log on Postgres (PRD 7.2 to 7.5): the writer inside the caller's transaction, the
 * envelope, and the reader below the transaction horizon in (xid, event_id) order, with separate
 * connections for the transactions that race (GUARDRAILS 9).
 */

const EVENT_LOG_CHANGESET = '01960000-0000-7000-8000-000000000001';

const EVENT_LOG_OTHER_CHANGESET = '01960000-0000-7000-8000-000000000002';

/**
 * The Clock of every write in this file.
 */
function eventClock(): FakeClock
{
    return new FakeClock(new DateTimeImmutable('2026-04-01T08:00:00.123456Z'));
}

beforeEach(function (): void {
    app(PartitionFixtures::class)->coverClock(eventClock(), new DateInterval('P1D'));
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
});

function eventWriter(FakeClock $clock, ?Connection $on = null): EventWriter
{
    return new EventWriter(app('db'), $clock, $on?->getName());
}

function eventReader(): EventReader
{
    return new EventReader(app('db'));
}

/**
 * The events of the stream after the cursor, read again until $count are below the horizon, for at
 * most 30 seconds. The horizon is the oldest transaction open on the whole server, so another
 * checkout's suite can hold it back for a moment; an event this test committed is read once that
 * transaction ends.
 *
 * @return list<StoredEvent>
 */
function eventsOnceBelowHorizon(int $count, EventStream $stream = EventStream::Interactive, ?EventPosition $after = null): array
{
    $deadline = microtime(true) + 30;

    do {
        $events = eventReader()->after($stream, $after ?? EventPosition::start(), max(100, $count));

        if (count($events) >= $count) {
            return $events;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new AssertionFailedError(sprintf('Only %d of %d events were below the transaction horizon after 30 seconds.', count($events), $count));
}

/**
 * The (aggregate id, aggregate version) of each event, in the order read.
 *
 * @param  list<StoredEvent>  $events
 * @return list<string>
 */
function eventAggregates(array $events): array
{
    return array_map(static fn (StoredEvent $event): string => $event->aggregate->id->value.'@'.$event->aggregate->version, $events);
}

/**
 * Drops every partition of one stream's table as the owner role. The next test covers them again.
 */
function dropEventPartitions(string $table): void
{
    $owner = DB::connection('pgsql_owner');

    foreach ($owner->select('select inhrelid::regclass::text as name from pg_inherits where inhparent = ?::regclass', [$table]) as $partition) {
        $name = is_object($partition) && property_exists($partition, 'name') && is_string($partition->name) ? $partition->name : throw new AssertionFailedError('Expected a partition name.');
        $owner->statement(sprintf('drop table %s', $name));
    }
}

function countEvents(): int
{
    $count = DB::connection()->scalar('select count(*) from events');

    return is_int($count) ? $count : -1;
}

it('refuses to write outside a transaction and writes nothing', function (): void {
    expect(fn (): array => eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-1', 1)]))
        ->toThrow(TransactionRequired::class, 'the connection has none open');

    expect(countEvents())->toBe(0);
});

it('leaves no event when the caller rolls back', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    $positions = eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [
        CounterRaised::of('counter-1', 1),
        CounterRaised::of('counter-2', 1),
    ]);

    expect($positions)->toHaveCount(2)
        ->and($db->scalar('select count(*) from events'))->toBe(2);

    $db->rollBack();

    expect(countEvents())->toBe(0)
        ->and(eventReader()->after(EventStream::Interactive, EventPosition::start(), 100))->toBe([]);
});

it('writes the envelope of PRD 7.2 and reads it back with the payload\'s data', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    $positions = eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [
        CounterRaised::of('counter-1', 3, after: 7),
        CounterRaised::of('counter-2', 1),
    ]);
    $xid = $db->scalar('select pg_current_xact_id()::text');
    $timeline = $db->scalar('select timeline_id from pg_control_checkpoint()');
    $db->commit();

    expect($positions[0]->eventId)->toBeLessThan($positions[1]->eventId)
        ->and((string) $positions[0]->xid)->toBe($xid)
        ->and($positions[1]->xid)->toBe($positions[0]->xid);

    $events = eventsOnceBelowHorizon(2);
    $event = $events[0];

    expect(eventAggregates($events))->toBe(['counter-1@3', 'counter-2@1'])
        ->and($event->position->equals($positions[0]))->toBeTrue()
        ->and($event->occurredAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-01T08:00:00.123456+00:00')
        ->and($event->changesetId->toString())->toBe(EVENT_LOG_CHANGESET)
        ->and($event->stream)->toBe(EventStream::Interactive)
        ->and($event->generation)->toBe($timeline)
        ->and($event->aggregate->type->value)->toBe('counter')
        ->and($event->type->name)->toBe('counter.raised')
        ->and($event->type->version)->toBe(1)
        ->and($event->data)->toEqual(CounterRaised::of('counter-1', 3, after: 7)->payload()->data())
        ->and($event->data->get('label')->asHash()->equals(TextHash::of('A label')))->toBeTrue();
});

it('does not skip an event whose transaction commits later with a lower event_id, and reads it only once it is below the horizon', function (): void {
    [$first, $second] = app(IndependentConnections::class)->open(2);

    // The first transaction writes the lower event_id and stays open.
    $first->beginTransaction();
    [$early] = eventWriter(eventClock(), $first)->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-early', 1)]);

    // The second writes a higher event_id and commits first.
    $second->beginTransaction();
    [$late] = eventWriter(eventClock(), $second)->write(ChangesetId::fromString(EVENT_LOG_OTHER_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-late', 1)]);
    $second->commit();

    expect($early->eventId)->toBeLessThan($late->eventId)
        ->and(DB::connection()->scalar('select count(*) from events where event_id = ?', [$late->eventId]))->toBe(1);

    // A reader that took "event_id above my cursor" would return the committed event now and move
    // past the open one. Below the horizon neither is returned while the first is open.
    expect(eventReader()->after(EventStream::Interactive, EventPosition::start(), 100))->toBe([]);

    $first->commit();

    $events = eventsOnceBelowHorizon(2);

    expect(eventAggregates($events))->toBe(['counter-early@1', 'counter-late@1'])
        ->and($events[0]->position->equals($early))->toBeTrue()
        ->and($events[1]->position->equals($late))->toBeTrue()
        ->and(eventReader()->after(EventStream::Interactive, $events[1]->position, 100))->toBe([]);
});

it('orders by the writing transaction\'s id before the event_id', function (): void {
    [$older, $newer] = app(IndependentConnections::class)->open(2);

    // The older transaction takes its transaction id first and writes after the newer one.
    $older->beginTransaction();
    $older->select('select pg_current_xact_id()');

    $newer->beginTransaction();
    [$lowId] = eventWriter(eventClock(), $newer)->write(ChangesetId::fromString(EVENT_LOG_OTHER_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-newer', 1)]);
    $newer->commit();

    [$highId] = eventWriter(eventClock(), $older)->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-older', 1)]);

    expect(eventReader()->after(EventStream::Interactive, EventPosition::start(), 100))->toBe([]);

    $older->commit();

    $events = eventsOnceBelowHorizon(2);

    expect($lowId->eventId)->toBeLessThan($highId->eventId)
        ->and($highId->xid)->toBeLessThan($lowId->xid)
        ->and(eventAggregates($events))->toBe(['counter-older@1', 'counter-newer@1']);
});

it('reads a stream after a cursor in pages, and each stream on its own', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [
        CounterRaised::of('counter-1', 1),
        CounterRaised::of('counter-1', 2),
        CounterRaised::of('counter-1', 3),
    ]);
    eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_OTHER_CHANGESET), EventStream::Bulk, [CounterRaised::of('counter-bulk', 1)]);
    $db->commit();

    $all = eventsOnceBelowHorizon(3);
    $page = eventReader()->after(EventStream::Interactive, EventPosition::start(), 2);
    $rest = eventReader()->after(EventStream::Interactive, $page[1]->position, 2);

    expect(eventAggregates($all))->toBe(['counter-1@1', 'counter-1@2', 'counter-1@3'])
        ->and(eventAggregates($page))->toBe(['counter-1@1', 'counter-1@2'])
        ->and(eventAggregates($rest))->toBe(['counter-1@3'])
        ->and(eventAggregates(eventsOnceBelowHorizon(1, EventStream::Bulk)))->toBe(['counter-bulk@1'])
        ->and(eventsOnceBelowHorizon(1, EventStream::Bulk)[0]->stream)->toBe(EventStream::Bulk);
});

it('refuses a read of fewer than one or more than the most events', function (int $limit): void {
    expect(fn (): array => eventReader()->after(EventStream::Interactive, EventPosition::start(), $limit))
        ->toThrow(InvalidArgumentException::class, sprintf('Read 1 to 10000 events at a time, not %d.', $limit));
})->with([0, -1, EventReader::MAX_LIMIT + 1]);

it('reads the most events at once', function (): void {
    expect(eventReader()->after(EventStream::Interactive, EventPosition::start(), EventReader::MAX_LIMIT))->toBe([]);
});

it('writes more events than one statement carries, in order', function (): void {
    $events = [];

    for ($version = 1; $version <= EventWriter::CHUNK + 5; $version++) {
        $events[] = CounterRaised::of('counter-1', $version);
    }

    $db = DB::connection();
    $db->beginTransaction();
    $positions = eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, $events);
    $db->commit();

    $read = eventsOnceBelowHorizon(EventWriter::CHUNK + 5);
    $ids = array_map(static fn (EventPosition $position): int => $position->eventId, $positions);
    $sorted = $ids;
    sort($sorted);

    expect($positions)->toHaveCount(EventWriter::CHUNK + 5)
        ->and($ids)->toBe($sorted)
        ->and(count(array_unique($ids)))->toBe(EventWriter::CHUNK + 5)
        ->and($read[EventWriter::CHUNK]->aggregate->version)->toBe(EventWriter::CHUNK + 1)
        ->and($read[EventWriter::CHUNK]->position->equals($positions[EventWriter::CHUNK]))->toBeTrue();
});

it('writes nothing and runs no statement for no events, inside a transaction', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    $db->enableQueryLog();
    $positions = eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, []);
    $log = $db->getQueryLog();
    $db->disableQueryLog();
    $db->commit();

    expect($positions)->toBe([])
        ->and($log)->toBe([]);
});

it('throws PartitionMissing when no partition of the stream covers the next event_id', function (): void {
    dropEventPartitions('events_bulk');

    $db = DB::connection();
    $db->beginTransaction();

    try {
        expect(fn (): array => eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Bulk, [CounterRaised::of('counter-1', 1)]))
            ->toThrow(PartitionMissing::class, 'events_bulk');
    } finally {
        $db->rollBack();
    }
});

it('gives the app role SELECT and INSERT on events and no UPDATE or DELETE', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    eventWriter(eventClock())->write(ChangesetId::fromString(EVENT_LOG_CHANGESET), EventStream::Interactive, [CounterRaised::of('counter-1', 1)]);
    $db->commit();

    expect(fn (): int => DB::connection()->update('update events set aggregate_version = 2'))->toThrow(QueryException::class, 'permission denied')
        ->and(fn (): int => DB::connection()->delete('delete from events'))->toThrow(QueryException::class, 'permission denied')
        ->and(countEvents())->toBe(1);
});

it('lets the app role move a cursor, but not to another subscription or stream', function (): void {
    $db = DB::connection();
    $db->insert("insert into event_cursors (subscription, stream, xid, event_id, updated_at) values ('fragments', 'interactive', '0', 0, now())");

    expect($db->update("update event_cursors set xid = '12', event_id = 4, updated_at = now() where subscription = 'fragments'"))->toBe(1)
        ->and(fn (): int => $db->update("update event_cursors set subscription = 'search'"))->toThrow(QueryException::class, 'permission denied')
        ->and(fn (): int => $db->update("update event_cursors set stream = 'bulk'"))->toThrow(QueryException::class, 'permission denied')
        ->and(fn (): int => $db->delete('delete from event_cursors'))->toThrow(QueryException::class, 'permission denied')
        ->and($db->selectOne('select xid::text as xid, event_id from event_cursors'))->toEqual((object) ['xid' => '12', 'event_id' => 4]);
});

it('lets the app role park an aggregate for a subscription and remove it again', function (): void {
    $db = DB::connection();
    $db->insert("insert into event_parked_aggregates (subscription, aggregate_type, aggregate_id, stream, xid, event_id, attempts, parked_at) values ('fragments', 'counter', 'counter-1', 'interactive', '10', 3, 5, now())");

    expect(fn (): bool => $db->insert("insert into event_parked_aggregates (subscription, aggregate_type, aggregate_id, stream, xid, event_id, attempts, parked_at) values ('fragments', 'counter', 'counter-1', 'interactive', '11', 4, 1, now())"))
        ->toThrow(QueryException::class, 'event_parked_aggregates_pkey')
        ->and($db->update("update event_parked_aggregates set attempts = 6 where subscription = 'fragments'"))->toBe(1)
        ->and($db->delete("delete from event_parked_aggregates where subscription = 'fragments'"))->toBe(1);
});

it('takes the transaction id and the failover generation from the column defaults', function (): void {
    $db = DB::connection();
    $db->beginTransaction();
    $db->insert("insert into events (occurred_at, changeset_id, stream, aggregate_type, aggregate_id, aggregate_version, type, type_version, data) values (now(), ?, 'interactive', 'counter', 'counter-1', 1, 'counter.raised', 1, '{}')", [EVENT_LOG_CHANGESET]);
    $row = $db->selectOne('select xid = pg_current_xact_id() as own_xid, generation = (select timeline_id from pg_control_checkpoint()) as timeline from events');
    $db->rollBack();

    expect($row)->toEqual((object) ['own_xid' => true, 'timeline' => true]);
});

it('refuses an event whose values are not in their form', function (string $column, string $value): void {
    $db = DB::connection();
    $row = [
        'aggregate_type' => 'counter',
        'aggregate_id' => 'counter-1',
        'type' => 'counter.raised',
        'data' => '{}',
    ];
    $row[$column] = $value;

    expect(fn (): bool => $db->insert(
        "insert into events (occurred_at, changeset_id, stream, aggregate_type, aggregate_id, aggregate_version, type, type_version, data) values (now(), ?, 'interactive', ?, ?, 1, ?, 1, ?::jsonb)",
        [EVENT_LOG_CHANGESET, $row['aggregate_type'], $row['aggregate_id'], $row['type'], $row['data']],
    ))->toThrow(QueryException::class, 'events_'.$column);
})->with([
    'an aggregate type with a capital' => ['aggregate_type', 'Counter'],
    'an aggregate id with a space' => ['aggregate_id', 'counter one'],
    'a type of one segment' => ['type', 'raised'],
    'data that is a list' => ['data', '[]'],
]);

it('is kept by cms:partitions:maintain, a runway of partitions ahead of the sequence per stream', function (): void {
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:partitions:maintain');
    $output = $artisan->output();

    expect($status)->toBe(0)
        ->and($output)->toContain('runway events_interactive until id 3000000 (2 partitions ahead of id 0)')
        ->and($output)->toContain('runway events_bulk until id 3000000 (2 partitions ahead of id 0)');

    $partitions = DB::connection('pgsql_owner')->select(
        "select inhparent::regclass::text as parent, inhrelid::regclass::text as partition from pg_inherits where inhparent in ('events_interactive'::regclass, 'events_bulk'::regclass) order by 1, 2",
    );

    $names = array_map(
        static fn (mixed $row): string => is_object($row) && isset($row->parent, $row->partition) && is_string($row->parent) && is_string($row->partition) ? $row->parent.' '.$row->partition : '',
        $partitions,
    );

    expect($names)->toBe([
        'events_bulk events_bulk_p0000000000000000000',
        'events_bulk events_bulk_p0000000000001000000',
        'events_bulk events_bulk_p0000000000002000000',
        'events_interactive events_interactive_p0000000000000000000',
        'events_interactive events_interactive_p0000000000001000000',
        'events_interactive events_interactive_p0000000000002000000',
    ]);
});
