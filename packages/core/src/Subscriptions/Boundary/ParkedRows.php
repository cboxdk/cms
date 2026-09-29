<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Events\Boundary\EventDataJson;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Maps the rows of event_parked_aggregates and event_cursors as the SubscriptionLog's statements
 * select them: xid as text, because PDO has no type for xid8, and times in UTC in
 * EventDataJson::TIME_FORMAT. A row that is not what the statement selects is an
 * UnexpectedValueException.
 */
#[Internal]
final readonly class ParkedRows
{
    /**
     * @param  array<mixed>  $rows
     * @return list<ParkedAggregate>
     */
    public static function parked(array $rows): array
    {
        return array_map(self::parking(...), array_values($rows));
    }

    public static function parking(mixed $row): ParkedAggregate
    {
        $stream = EventStream::tryFrom(self::string($row, 'stream'))
            ?? throw new UnexpectedValueException('A parked aggregate\'s stream is not a stream.');
        $releasedAt = self::column($row, 'released_at');

        return new ParkedAggregate(
            new SubscriptionName(self::string($row, 'subscription')),
            self::aggregate($row),
            $stream,
            self::position($row),
            self::integer($row, 'attempts'),
            self::time(self::string($row, 'parked_at')),
            $releasedAt === null ? null : self::time(self::string($row, 'released_at')),
        );
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<AggregateKey>
     */
    public static function aggregates(array $rows): array
    {
        return array_map(self::aggregate(...), array_values($rows));
    }

    /**
     * A cursor row, (xid, event_id), or the start when the statement found none.
     */
    public static function cursor(mixed $row): EventPosition
    {
        return $row === null ? EventPosition::start() : self::position($row);
    }

    private static function aggregate(mixed $row): AggregateKey
    {
        return new AggregateKey(new AggregateType(self::string($row, 'aggregate_type')), new EventIdentifier(self::string($row, 'aggregate_id')));
    }

    private static function position(mixed $row): EventPosition
    {
        $xid = filter_var(self::string($row, 'xid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return new EventPosition(
            is_int($xid) ? $xid : throw new UnexpectedValueException('A cursor\'s xid is not a transaction id PHP can hold.'),
            self::integer($row, 'event_id'),
        );
    }

    private static function integer(mixed $row, string $column): int
    {
        $value = self::column($row, $column);

        if (is_string($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s is not an integer.', $column));
    }

    private static function string(mixed $row, string $column): string
    {
        $value = self::column($row, $column);

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s is not text.', $column));
    }

    private static function column(mixed $row, string $column): mixed
    {
        if (! is_object($row) || ! property_exists($row, $column)) {
            throw new UnexpectedValueException(sprintf('The row has no %s.', $column));
        }

        return $row->{$column};
    }

    private static function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!'.EventDataJson::TIME_FORMAT, $value, new DateTimeZone('UTC'));

        return $time !== false && $time->format(EventDataJson::TIME_FORMAT) === $value
            ? $time
            : throw new UnexpectedValueException('A parked aggregate\'s time is not an instant.');
    }
}
