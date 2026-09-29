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
