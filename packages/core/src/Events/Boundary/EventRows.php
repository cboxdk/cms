<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Events\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps the rows the event log's statements return (PRD 7.2): the positions the writer's insert
 * returns and the events the reader selects. The statements select xid as text, because PDO has no
 * type for xid8, and occurred_at in UTC in EventDataJson::TIME_FORMAT.
 *
 * A row that is not what the statement selects is an InvalidEvent.
 */
#[Internal]
final readonly class EventRows
{
    /**
     * The positions of the inserted events, as (event_id, xid) rows, in event_id order.
     *
     * @param  array<mixed>  $rows
     * @return list<EventPosition>
     */
    public static function positions(array $rows): array
    {
        $positions = array_map(
            static fn (mixed $row): EventPosition => new EventPosition(self::xid($row), self::integer($row, 'event_id')),
            array_values($rows),
        );

        usort($positions, static fn (EventPosition $a, EventPosition $b): int => $a->eventId <=> $b->eventId);

        return $positions;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<StoredEvent>
     */
    public static function events(array $rows): array
    {
        return array_map(self::event(...), array_values($rows));
    }

    public static function event(mixed $row): StoredEvent
    {
        $stream = EventStream::tryFrom(self::string($row, 'stream'))
            ?? throw InvalidEvent::stored('its stream is not a stream.');

        return new StoredEvent(
            position: new EventPosition(self::xid($row), self::integer($row, 'event_id')),
            occurredAt: self::time(self::string($row, 'occurred_at')),
            changesetId: ChangesetId::fromString(self::string($row, 'changeset_id')),
            stream: $stream,
            generation: self::integer($row, 'generation'),
            aggregate: new EventAggregate(
                new AggregateType(self::string($row, 'aggregate_type')),
                new EventIdentifier(self::string($row, 'aggregate_id')),
                self::integer($row, 'aggregate_version'),
            ),
            type: new EventType(self::string($row, 'type'), self::integer($row, 'type_version')),
            data: EventDataJson::decode(self::string($row, 'data')),
        );
    }

    /**
     * A transaction id: an xid8 as text, which fits in a PHP int for the first 2^31 epochs of the
     * 32-bit transaction counter.
     */
    private static function xid(mixed $row): int
    {
        $xid = filter_var(self::string($row, 'xid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($xid) ? $xid : throw InvalidEvent::stored('its xid is not a transaction id PHP can hold.');
    }

    private static function integer(mixed $row, string $column): int
    {
        $value = self::column($row, $column);

        if (is_string($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }

        return is_int($value) ? $value : throw InvalidEvent::stored(sprintf('its %s is not an integer.', $column));
    }

    private static function string(mixed $row, string $column): string
    {
        $value = self::column($row, $column);

        return is_string($value) ? $value : throw InvalidEvent::stored(sprintf('its %s is not text.', $column));
    }

    private static function column(mixed $row, string $column): mixed
    {
        if (! is_object($row) || ! property_exists($row, $column)) {
            throw InvalidEvent::stored(sprintf('the row has no %s.', $column));
        }

        return $row->{$column};
    }

    private static function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!'.EventDataJson::TIME_FORMAT, $value, new DateTimeZone('UTC'));

        return $time !== false && $time->format(EventDataJson::TIME_FORMAT) === $value ? $time : throw InvalidEvent::stored('its occurred_at is not an instant.');
    }
}
