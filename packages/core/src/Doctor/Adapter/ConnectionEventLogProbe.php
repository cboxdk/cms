<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * The event log on the doctor's connection, as the app role on the primary (PRD 7.4). The
 * subscriptions come from the registry cache, the one the runners read.
 *
 * A subscription's oldest unhandled event is, per stream, the first event after its cursor in
 * (xid, event_id) order of a type it receives, the event its runner hands it next; without a
 * cursor, the first event of the stream. It counts events of committed transactions whether or
 * not they are below the transaction horizon yet, because an event above the horizon waits too.
 * The first in that order is not always the one with the earliest occurred_at, but the two lie
 * within the life of one transaction, and the read uses the index events_position instead of
 * scanning every event past the cursor.
 */
#[Internal]
final readonly class ConnectionEventLogProbe implements EventLogProbe
{
    private const string CURSOR = 'select xid::text as xid, event_id::text as event_id from event_cursors where subscription = ? and stream = ?';

    private const string OLDEST = <<<'SQL'
        select to_char(e.occurred_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as occurred_at
        from events e
        join jsonb_to_recordset(?::jsonb) as t(name text, version integer)
            on e.type = t.name and e.type_version = t.version
        where e.stream = ?
            and (e.xid, e.event_id) > (?::xid8, ?::bigint)
        order by e.xid, e.event_id
        limit 1
        SQL;

    private const string PARKED = <<<'SQL'
        select subscription, count(*)::int as parked
        from event_parked_aggregates
        where released_at is null
        group by subscription
        order by subscription
        SQL;

    public function __construct(
        private DoctorConnection $connection,
        private RegistryCache $registry,
    ) {}

    #[Override]
    public function lag(): array
    {
        try {
            $subscribers = $this->registry->read()->subscribers;
        } catch (MalformedRegistryCache|RegistryCacheMissing $unreadable) {
            throw ProbeFailed::violation(sprintf('The registry cache cannot be read: %s', $unreadable->getMessage()), $unreadable);
        }

        return array_map($this->subscription(...), $subscribers);
    }

    #[Override]
    public function parked(): array
    {
        return array_map(
            static fn (CatalogRow $row): ParkedCount => new ParkedCount(new SubscriptionName($row->string('subscription')), $row->int('parked')),
            CatalogRow::all($this->connection->rows(self::PARKED)),
        );
    }

    private function subscription(SubscriberEntry $entry): SubscriptionLag
    {
        $types = json_encode(
            array_map(static fn (SubscribedEvent $event): array => ['name' => $event->type->name, 'version' => $event->type->version], $entry->events),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $oldest = null;
        $stream = null;

        foreach (EventStream::cases() as $candidate) {
            $cursor = CatalogRow::all($this->connection->rows(self::CURSOR, [$entry->name->value, $candidate->value]));
            [$xid, $eventId] = $cursor === [] ? ['0', '0'] : [$cursor[0]->string('xid'), $cursor[0]->string('event_id')];
            $row = CatalogRow::all($this->connection->rows(self::OLDEST, [$types, $candidate->value, $xid, $eventId]));

            if ($row === []) {
                continue;
            }

            $occurred = new DateTimeImmutable($row[0]->string('occurred_at'), new DateTimeZone('UTC'));

            if (! $oldest instanceof DateTimeImmutable || $occurred < $oldest) {
                $oldest = $occurred;
                $stream = $candidate;
            }
        }

        return new SubscriptionLag($entry->name, $entry->lane, $oldest, $stream);
    }
}
