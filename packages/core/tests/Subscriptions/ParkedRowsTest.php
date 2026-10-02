<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Core\Subscriptions\Boundary\ParkedRows;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\ParkedAggregate;
use stdClass;
use UnexpectedValueException;

/*
 * The rows of event_parked_aggregates and event_cursors as the Postgres subscription log reads
 * them: the columns it needs, each of its kind, or the row is refused.
 */

/**
 * A parked aggregate's row as pdo_pgsql gives it, with the columns $changes replaces.
 *
 * @param  array<string, mixed>  $changes
 */
function parkedRow(array $changes = []): stdClass
{
    return (object) [
        'subscription' => 'test.counters',
        'aggregate_type' => 'counter',
        'aggregate_id' => 'a',
        'stream' => 'interactive',
        'xid' => '42',
        'event_id' => '7',
        'attempts' => '3',
        'parked_at' => '2026-10-01T12:00:00.000000Z',
        'released_at' => null,
        ...$changes,
    ];
}

it('reads parked aggregates and aggregates from rows given in any keys, as lists', function (): void {
    $parked = ParkedRows::parked(['a' => parkedRow(), 'b' => parkedRow(['aggregate_id' => 'b', 'released_at' => '2026-10-01T12:05:00.000000Z'])]);
    $aggregates = ParkedRows::aggregates(['x' => parkedRow(), 'y' => parkedRow(['aggregate_id' => 'b'])]);

    expect($parked)->toBeList()
        ->and([$parked[0]->aggregate->toString(), $parked[0]->stream, $parked[0]->position->xid, $parked[0]->position->eventId, $parked[0]->attempts, $parked[0]->isReleased()])
        ->toBe(['counter:a', EventStream::Interactive, 42, 7, 3, false])
        ->and($parked[1]->isReleased())->toBeTrue()
        ->and(array_map(static fn (AggregateKey $key): string => $key->toString(), $aggregates))->toBe(['counter:a', 'counter:b']);
});

it('reads a cursor at transaction 0, and the start when there is no row', function (): void {
    expect(ParkedRows::cursor(parkedRow(['xid' => '0', 'event_id' => 0]))->xid)->toBe(0)
        ->and(ParkedRows::cursor(null)->eventId)->toBe(0);
});

it('refuses a row that is not an object, lacks a column, or holds a value of another kind', function (mixed $row, string $message): void {
    expect(static fn (): ParkedAggregate => ParkedRows::parking($row))->toThrow(UnexpectedValueException::class, $message);
})->with([
    'an array' => [['subscription' => 'test.counters'], 'The row has no stream.'],
    'a missing column' => [(object) ['stream' => 'interactive'], 'The row has no released_at.'],
    'a stream that is not one' => [parkedRow(['stream' => 'nightly']), 'A parked aggregate\'s stream is not a stream.'],
    'a negative transaction id' => [parkedRow(['xid' => '-1']), 'A cursor\'s xid is not a transaction id PHP can hold.'],
    'a transaction id that is not a number' => [parkedRow(['xid' => 'x']), 'A cursor\'s xid is not a transaction id PHP can hold.'],
    'attempts that are not a number' => [parkedRow(['attempts' => 'three']), 'The column attempts is not an integer.'],
    'attempts that are a float' => [parkedRow(['attempts' => 3.0]), 'The column attempts is not an integer.'],
    'a time that is no instant' => [parkedRow(['parked_at' => '2026-13-45T12:00:00.000000Z']), 'A parked aggregate\'s time is not an instant.'],
    'a time without microseconds' => [parkedRow(['parked_at' => '2026-10-01T12:00:00Z']), 'A parked aggregate\'s time is not an instant.'],
]);
