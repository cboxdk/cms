---
title: Events
weight: 42
description: "How an addon declares an event: a class with a versioned payload DTO that carries ids, versions, values that are not text and hashes of text, and how the kernel writes it to the event log and reads it back below the transaction horizon."
---

# Events

<!-- extension-point: Cbox\Cms\Contracts\Events\Event -->
<!-- extension-point: Cbox\Cms\Contracts\Events\EventPayload -->
<!-- extension-point: Cbox\Cms\Contracts\Ids\Identifier -->

Search, feeds, invalidation, webhooks and every other projection react to committed changes through the event log (PRD 7). The log is a transactional outbox in Postgres: the kernel writes the events of a changeset in the same transaction as the change, so either both exist or neither does. It is not event sourcing. The tables are the truth, and an event only says that the truth changed: "aggregate X is now at version V, read the state".

## An event is a class

An event implements `Cbox\Cms\Contracts\Events\Event` (GUARDRAILS 2.4). It has three methods:

- `type()`, a static method, gives the `EventType`: the name, dot-separated snake_case such as `variant.released`, and the version of the payload, from 1. The name comes from the class, so no event name exists as a string without one.
- `aggregate()` gives the `EventAggregate`: the aggregate's type, such as `entry` or `warehouse`, its id and the version the changeset left it at. The version starts at 1 and rises with every changeset that changes the aggregate.
- `payload()` gives the payload, a final readonly DTO that implements `Cbox\Cms\Contracts\Events\EventPayload`. When the payload changes shape it gets a new class and the event's type the next version.

## What an event may carry

An event carries ids, versions, values that are not text and hashes of text, never content (PRD 6.5 invariant 10, 7.2). That keeps the log free of personal and classified data, so it can be kept, shipped to webhooks and replayed without a question of what is in it. A payload's properties are therefore limited to:

- `int`, `bool` and `null`;
- `DateTimeImmutable`;
- a backed enum, whose string values are words without spaces;
- an id value object that implements `Cbox\Cms\Contracts\Ids\Identifier`, such as `ChangesetId`: its `toString()` is 1 to 255 visible ASCII characters without spaces;
- a `TextHash`, the SHA-256 of a text, where a subscriber needs to see that a text changed;
- a list of these.

The testkit's PHPStan rule `cboxCms.eventPayloadText` reports every other property of a payload, test code included, and no comment hides it (see [static analysis](static-analysis.md)). A `string` property is reported even when it holds an id or a hash: give it its value object. A float, an array with keys, a `DateTimeInterface` or an object that is none of the above is reported too, because it can hold what an event must not.

`payload()->data()` gives the same values as `EventData`: named `EventDatum`s, made only from typed values. There is no datum for a string, and an id or an enum value that looks like text, with spaces or outside ASCII, is refused with `InvalidEvent`, whose message never repeats the refused string.

## The log

The kernel writes a changeset's events with `Cbox\Cms\Core\Events\Infrastructure\EventWriter` in the command transaction (PRD 7.3). The writer runs on the caller's connection and never begins a transaction: without an open one it throws `TransactionRequired` and writes nothing, and a rollback leaves no event. Each row is the envelope of PRD 7.2:

| Column | What |
|---|---|
| `event_id` | the order of insertion, from the sequence `events_event_id_seq` |
| `xid` | the writing transaction's id, `pg_current_xact_id()` |
| `occurred_at` | the `Clock`'s time at the write |
| `changeset_id` | the command's changeset |
| `stream` | `interactive` or `bulk` (`EventStream`, PRD 7.5) |
| `generation` | the failover generation, the timeline Postgres writes WAL on (PRD 7.15) |
| `aggregate_type`, `aggregate_id`, `aggregate_version` | the event's `EventAggregate` |
| `type`, `type_version` | the event's `EventType` |
| `data` | the payload's `EventData` as JSON |

`Cbox\Cms\Core\Events\Infrastructure\EventReader` reads a stream after a cursor, an `EventPosition` of (xid, event_id). Transactions take their event_ids at insert and commit in another order, so reading "event_id above my cursor" would skip an event whose transaction commits later with a lower id. The reader therefore returns only events of transactions that have certainly ended, below the transaction horizon `xid < pg_snapshot_xmin(pg_current_snapshot())`, ordered by (xid, event_id) (PRD 7.4). No transaction below the horizon can still commit, so nothing is skipped. The horizon is the oldest transaction open on the primary, so delivery waits for it; that is why command transactions are short. The reader always reads the primary.

A subscription keeps a cursor per stream in `event_cursors`, in the same database as the events, so it commits its cursor with its own writes (PRD 7.15). An aggregate a subscription gives up on after failed attempts is parked in `event_parked_aggregates` (PRD 7.8).

Each stream has its own partitions on `event_id`, kept by `cms:partitions:maintain` as the tables `events_interactive` and `events_bulk`: a runway of partitions of a million ids ahead of the sequence, and a partition the sequence has passed is dropped once its newest event is 30 days old (PRD 7.10, see [partitions](../developers/partitions.md)).

## Example

An addon's event, `stock.counted`, about a warehouse. The id and the enum are the addon's own types:

<!-- example-file: examples/Postgres/Events/WarehouseId.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Ids\Identifier;
use InvalidArgumentException;

/**
 * The id of an addon's aggregate, a warehouse. As an Identifier it can be carried by an event.
 */
final readonly class WarehouseId implements Identifier
{
    public function __construct(public string $value)
    {
        if (preg_match('/\Awh-[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('A warehouse id is "wh-" and digits.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
```

<!-- example-file: examples/Postgres/Events/StockLevel.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

/**
 * How full a warehouse is: a closed set, so an event may carry it.
 */
enum StockLevel: string
{
    case Empty = 'empty';
    case Low = 'low';
    case Full = 'full';
}
```

The payload holds the counter's note only as a hash:

<!-- example-file: examples/Postgres/Events/StockCountedV1.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\TextHash;
use DateTimeImmutable;

/**
 * Version 1 of the payload of stock.counted. The counter's note is text, so the payload carries
 * only its hash; a string property here would fail the analysis (cboxCms.eventPayloadText).
 */
final readonly class StockCountedV1 implements EventPayload
{
    public function __construct(
        public int $before,
        public int $after,
        public StockLevel $level,
        public DateTimeImmutable $countedAt,
        public TextHash $noteHash,
    ) {}

    public function data(): EventData
    {
        return EventData::empty()
            ->with('before', EventDatum::integer($this->before))
            ->with('after', EventDatum::integer($this->after))
            ->with('level', EventDatum::enum($this->level))
            ->with('counted_at', EventDatum::time($this->countedAt))
            ->with('note_hash', EventDatum::hash($this->noteHash));
    }
}
```

<!-- example-file: examples/Postgres/Events/StockCounted.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;

/**
 * An addon's event: a warehouse's stock was counted. The class names the event, stock.counted
 * version 1; its payload is the versioned DTO StockCountedV1.
 */
final readonly class StockCounted implements Event
{
    public function __construct(
        private WarehouseId $warehouse,
        private int $version,
        private StockCountedV1 $payload,
    ) {}

    public static function type(): EventType
    {
        return new EventType('stock.counted', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('warehouse'), $this->warehouse, $this->version);
    }

    public function payload(): StockCountedV1
    {
        return $this->payload;
    }
}
```

The test writes the event in a transaction, reads it back below the horizon and checks the envelope and the data. It runs in the `Postgres` suite:

<!-- example: examples/Postgres/Events/RecordStockCountedTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Events\Infrastructure\EventReader;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Examples\Postgres\Harness\AddonTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * An addon's event through the event log: written in the command transaction, read back below the
 * transaction horizon with its envelope and its data, and a subscriber turning the data back into
 * typed values.
 */
final class RecordStockCountedTest extends AddonTestCase
{
    #[Test]
    public function the_log_holds_the_event_with_its_envelope_and_data(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
        $changeset = ChangesetId::fromString('01f2a3b4-0000-7000-8000-000000000001');
        $event = new StockCounted(new WarehouseId('wh-7'), 12, new StockCountedV1(
            before: 40,
            after: 3,
            level: StockLevel::Low,
            countedAt: new DateTimeImmutable('2031-05-01T08:55:00Z'),
            noteHash: TextHash::of('Two pallets were damaged.'),
        ));

        // The kernel writes a changeset's events inside its command transaction.
        DB::transaction(static function () use ($clock, $changeset, $event): void {
            new EventWriter(app('db'), $clock)->write($changeset, EventStream::Interactive, [$event]);
        });

        $stored = $this->readOnceCommitted();

        self::assertSame('stock.counted', $stored->type->name);
        self::assertSame(1, $stored->type->version);
        self::assertSame('warehouse', $stored->aggregate->type->value);
        self::assertSame('wh-7', $stored->aggregate->id->toString());
        self::assertSame(12, $stored->aggregate->version);
        self::assertTrue($stored->changesetId->equals($changeset));
        self::assertSame('2031-05-01T09:00:00+00:00', $stored->occurredAt->format(DATE_ATOM));

        // A subscriber reads the values back as the types they were written as.
        self::assertSame(3, $stored->data->get('after')->asInteger());
        self::assertSame(StockLevel::Low, StockLevel::from((string) $stored->data->get('level')->asEnumValue()));
        self::assertTrue($stored->data->get('note_hash')->asHash()->equals(TextHash::of('Two pallets were damaged.')));
        self::assertEquals($event->payload()->data(), $stored->data);
    }

    /**
     * The first event of the stream. The reader only returns events below the transaction horizon,
     * the oldest transaction still open on the server, so it asks again for a moment while another
     * transaction holds the horizon back.
     */
    private function readOnceCommitted(): StoredEvent
    {
        $reader = new EventReader(app('db'));
        $deadline = hrtime(true) + 30_000_000_000;

        do {
            $events = $reader->after(EventStream::Interactive, EventPosition::start(), 10);

            if ($events !== []) {
                return $events[0];
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The event was not below the transaction horizon within 30 seconds.');
    }
}
```
