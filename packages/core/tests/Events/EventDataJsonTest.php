<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Events;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Core\Events\Boundary\EventDataJson;
use Cbox\Cms\Core\Events\Boundary\EventRows;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use DateTimeImmutable;

/*
 * The JSON form of event data and the rows of the event log's statements (PRD 7.2): every kind
 * reads back as the kind it was written as, and anything else is refused.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function eventRow(array $overrides = []): object
{
    return (object) [
        'event_id' => 12,
        'xid' => '4567',
        'occurred_at' => '2026-04-01T08:00:00.123456Z',
        'changeset_id' => '01960000-0000-7000-8000-000000000001',
        'stream' => 'interactive',
        'generation' => 1,
        'aggregate_type' => 'counter',
        'aggregate_id' => 'counter-1',
        'aggregate_version' => 3,
        'type' => 'counter.raised',
        'type_version' => 1,
        'data' => '{"count": {"integer": 3}}',
        ...$overrides,
    ];
}

it('writes each kind as an object with one member named by the kind', function (): void {
    $data = EventData::empty()
        ->with('entry', EventDatum::identifier(new EventIdentifier('e-1')))
        ->with('title', EventDatum::hash(TextHash::of('A title')))
        ->with('count', EventDatum::integer(3))
        ->with('manual', EventDatum::boolean(true))
        ->with('at', EventDatum::time(new DateTimeImmutable('2026-04-01T10:00:00+02:00')))
        ->with('state', EventDatum::enumValue('released'))
        ->with('previous', EventDatum::null())
        ->with('tags', EventDatum::list(EventDatum::identifier(new EventIdentifier('a')), EventDatum::enumValue(2)));

    expect(EventDataJson::encode($data))->toBe(
        '{"at":{"time":"2026-04-01T08:00:00.000000Z"},"count":{"integer":3},"entry":{"identifier":"e-1"},"manual":{"boolean":true},"previous":{"null":null},"state":{"enum":"released"},"tags":{"list":[{"identifier":"a"},{"enum":2}]},"title":{"hash":"'.hash('sha256', 'A title').'"}}',
    )
        ->and(EventDataJson::decode(EventDataJson::encode($data)))->toEqual($data);
});

it('writes empty data as an empty object and reads it back', function (): void {
    expect(EventDataJson::encode(EventData::empty()))->toBe('{}')
        ->and(EventDataJson::decode('{}'))->toEqual(EventData::empty())
        ->and(EventDataJson::decode(EventDataJson::encode(CounterRaised::of('c', 1)->payload()->data())))->toEqual(CounterRaised::of('c', 1)->payload()->data());
});

it('refuses stored data that is not what encode() writes', function (string $json, string $reason): void {
    expect(static fn (): EventData => EventDataJson::decode($json))->toThrow(InvalidEvent::class, 'A stored event cannot be read: '.$reason);
})->with([
    'not JSON' => ['{', 'its data is not JSON.'],
    'a list' => ['[1]', 'its data is not a JSON object.'],
    'a number' => ['3', 'its data is not a JSON object.'],
    'a bare value' => ['{"a": 3}', 'a value of its data is not an object with one member.'],
    'two members' => ['{"a": {"integer": 1, "boolean": true}}', 'a value of its data is not an object with one member.'],
    'an unknown kind' => ['{"a": {"text": "A title"}}', 'a value of its data has an unknown kind.'],
    'an id that is a number' => ['{"a": {"identifier": 3}}', 'a value of kind identifier in its data has another type.'],
    'a hash that is a number' => ['{"a": {"hash": 3}}', 'a value of kind hash in its data has another type.'],
    'an integer that is text' => ['{"a": {"integer": "3"}}', 'a value of kind integer in its data has another type.'],
    'an integer that is a float' => ['{"a": {"integer": 3.5}}', 'a value of kind integer in its data has another type.'],
    'a boolean that is a number' => ['{"a": {"boolean": 1}}', 'a value of kind boolean in its data has another type.'],
    'a time in another form' => ['{"a": {"time": "2026-04-01 08:00:00"}}', 'a value of kind time in its data has another type.'],
    'a time that is a number' => ['{"a": {"time": 3}}', 'a value of kind time in its data has another type.'],
    'a time that does not exist' => ['{"a": {"time": "2026-02-30T08:00:00.000000Z"}}', 'a value of kind time in its data has another type.'],
    'an enum that is a boolean' => ['{"a": {"enum": true}}', 'a value of kind enum in its data has another type.'],
    'null that has a value' => ['{"a": {"null": 0}}', 'a value of kind null in its data has another type.'],
    'a list that is an object' => ['{"a": {"list": {"b": 1}}}', 'a value of kind list in its data has another type.'],
]);

it('refuses stored text as an id, hash or enum value', function (string $json): void {
    expect(static fn (): EventData => EventDataJson::decode($json))->toThrow(InvalidEvent::class);
})->with([
    '{"a": {"identifier": "A title"}}',
    '{"a": {"hash": "A title"}}',
    '{"a": {"enum": "A title"}}',
    '{"a": {"list": [{"identifier": "A title"}]}}',
    '{"A title": {"null": null}}',
]);

it('maps a row of the reader to a stored event', function (): void {
    $event = EventRows::event(eventRow());

    expect($event->position)->toEqual(new EventPosition(4567, 12))
        ->and($event->occurredAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-01T08:00:00.123456+00:00')
        ->and($event->changesetId->toString())->toBe('01960000-0000-7000-8000-000000000001')
        ->and($event->stream)->toBe(EventStream::Interactive)
        ->and($event->generation)->toBe(1)
        ->and($event->aggregate->type->value)->toBe('counter')
        ->and($event->aggregate->id->value)->toBe('counter-1')
        ->and($event->aggregate->version)->toBe(3)
        ->and($event->type->name)->toBe('counter.raised')
        ->and($event->type->version)->toBe(1)
        ->and($event->data->get('count')->asInteger())->toBe(3)
        ->and(EventRows::event(eventRow(['event_id' => '12', 'generation' => '2']))->generation)->toBe(2)
        ->and(EventRows::events([eventRow(), eventRow(['event_id' => 13])]))->toHaveCount(2);
});

it('gives the positions of inserted rows in event_id order', function (): void {
    expect(EventRows::positions([
        (object) ['event_id' => 9, 'xid' => '100'],
        (object) ['event_id' => 7, 'xid' => '100'],
    ]))->toEqual([new EventPosition(100, 7), new EventPosition(100, 9)])
        ->and(EventRows::positions([]))->toBe([]);
});

it('refuses a row that is not what the statements select', function (mixed $row, string $reason): void {
    expect(static fn (): object => EventRows::event($row))->toThrow(InvalidEvent::class, 'A stored event cannot be read: '.$reason);
})->with([
    'no object' => [[], 'the row has no stream.'],
    'a missing column' => [(object) ['stream' => 'interactive'], 'the row has no xid.'],
    'an unknown stream' => [eventRow(['stream' => 'other']), 'its stream is not a stream.'],
    'an xid past PHP ints' => [eventRow(['xid' => '18446744073709551615']), 'its xid is not a transaction id PHP can hold.'],
    'an xid that is no number' => [eventRow(['xid' => 'x']), 'its xid is not a transaction id PHP can hold.'],
    'an event_id that is no integer' => [eventRow(['event_id' => 1.5]), 'its event_id is not an integer.'],
    'an event_id that is text' => [eventRow(['event_id' => 'twelve']), 'its event_id is not an integer.'],
    'a type that is no text' => [eventRow(['type' => 3]), 'its type is not text.'],
    'an occurred_at in another form' => [eventRow(['occurred_at' => '2026-04-01 08:00:00+00']), 'its occurred_at is not an instant.'],
    'an occurred_at that does not exist' => [eventRow(['occurred_at' => '2026-02-30T08:00:00.000000Z']), 'its occurred_at is not an instant.'],
]);
