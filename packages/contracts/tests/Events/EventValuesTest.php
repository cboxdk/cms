<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Events;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;
use UnexpectedValueException;

/*
 * The values of the event contract (PRD 7.2, 6.5 invariant 10): an event carries ids, versions,
 * values that are not text and hashes of text, and each value refuses what is not in its form
 * without repeating a refused string.
 */

const EVENT_VALUES_CHANGESET = '01960000-0000-7000-8000-000000000001';

it('takes an event type of dot-separated snake_case segments and a version from 1', function (): void {
    $type = new EventType('variant.released', 1);

    expect($type->name)->toBe('variant.released')
        ->and($type->version)->toBe(1)
        ->and($type->equals(new EventType('variant.released', 1)))->toBeTrue()
        ->and($type->equals(new EventType('variant.released', 2)))->toBeFalse()
        ->and($type->equals(new EventType('variant.withdrawn', 1)))->toBeFalse()
        ->and(new EventType('acme.stock.counted', 3)->name)->toBe('acme.stock.counted')
        ->and(new EventType('a.'.str_repeat('b', 61), 1)->name)->toHaveLength(63);
});

it('refuses an event type name that is not dot-separated snake_case of at least two segments', function (string $name): void {
    expect(static fn (): EventType => new EventType($name, 1))
        ->toThrow(InvalidEvent::class, 'An event type name is dot-separated snake_case segments, at least two, such as "variant.released", at most 63 characters.');
})->with(['released', 'Variant.released', 'variant..released', 'variant.released.', '1variant.released', "variant.released\n", 'a.'.str_repeat('b', 62), '']);

it('refuses an event type version below 1', function (int $version): void {
    expect(static fn (): EventType => new EventType('variant.released', $version))
        ->toThrow(InvalidEvent::class, sprintf('An event type version starts at 1, got %d.', $version));
})->with([0, -1]);

it('takes an aggregate with a snake_case type, an id and a version from 1', function (): void {
    $aggregate = new EventAggregate(new AggregateType('entry'), ChangesetId::fromString(EVENT_VALUES_CHANGESET), 4);

    expect($aggregate->type->value)->toBe('entry')
        ->and($aggregate->id)->toEqual(new EventIdentifier(EVENT_VALUES_CHANGESET))
        ->and($aggregate->version)->toBe(4)
        ->and(new AggregateType('entry')->equals(new AggregateType('entry')))->toBeTrue()
        ->and(new AggregateType('entry')->equals(new AggregateType('variant')))->toBeFalse()
        ->and(new AggregateType(str_repeat('a', 63))->value)->toHaveLength(63);
});

it('refuses an aggregate type that is not snake_case', function (string $type): void {
    expect(static fn (): AggregateType => new AggregateType($type))
        ->toThrow(InvalidEvent::class, 'An aggregate type is snake_case, such as "entry" or "variant", at most 63 characters.');
})->with(['Entry', 'entry.variant', '_entry', '', str_repeat('a', 64), "entry\n"]);

it('refuses an aggregate version below 1', function (int $version): void {
    expect(static fn (): EventAggregate => new EventAggregate(new AggregateType('entry'), new EventIdentifier('e-1'), $version))
        ->toThrow(InvalidEvent::class, sprintf('An aggregate version starts at 1, got %d.', $version));
})->with([0, -1]);

it('takes an id of 1 to 255 visible ASCII characters', function (string $id): void {
    expect(new EventIdentifier($id)->toString())->toBe($id)
        ->and(EventIdentifier::of(new EventIdentifier($id))->value)->toBe($id)
        ->and(new EventIdentifier($id)->equals(new EventIdentifier($id)))->toBeTrue();
})->with(['e', 'entry:01960000-0000-7000-8000-000000000001', '!~', str_repeat('x', 255)]);

it('refuses an id with a space, a byte outside visible ASCII or no characters, and never repeats it', function (string $id): void {
    try {
        new EventIdentifier($id);
    } catch (InvalidEvent $refused) {
        expect($refused->getMessage())->toBe(sprintf(
            'An id in an event is 1 to 255 visible ASCII characters without spaces; got a string of %d bytes that is not. An event carries ids, never text (PRD 6.5 invariant 10).',
            strlen($id),
        ));

        return;
    }

    throw new UnexpectedValueException('The id was accepted.');
})->with(['A title with spaces', '', 'tab	here', 'æble', str_repeat('x', 256), "id\n"]);

it('keeps an id that is not an EventIdentifier as its canonical string', function (): void {
    $changeset = ChangesetId::fromString(EVENT_VALUES_CHANGESET);

    expect(EventIdentifier::of($changeset)->value)->toBe(EVENT_VALUES_CHANGESET)
        ->and(new EventIdentifier(EVENT_VALUES_CHANGESET)->equals($changeset))->toBeTrue();
});

it('hashes text with SHA-256 and keeps the digest in lower case', function (): void {
    expect(TextHash::of('A title')->value)->toBe(hash('sha256', 'A title'))
        ->and(new TextHash(strtoupper(hash('sha256', 'x')))->value)->toBe(hash('sha256', 'x'))
        ->and(TextHash::of('a')->equals(TextHash::of('a')))->toBeTrue()
        ->and(TextHash::of('a')->equals(TextHash::of('b')))->toBeFalse();
});

it('refuses a text hash that is not 64 hex digits', function (string $hash): void {
    expect(static fn (): TextHash => new TextHash($hash))->toThrow(InvalidEvent::class, 'A text hash is a SHA-256 digest as 64 hex digits.');
})->with(['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), 'A title']);

it('gives each datum back as the kind it was made as', function (): void {
    $time = new DateTimeImmutable('2026-04-01T10:00:00.5+02:00');
    $hash = TextHash::of('A title');

    expect(EventDatum::identifier(new EventIdentifier('e-1'))->asIdentifier()->value)->toBe('e-1')
        ->and(EventDatum::hash($hash)->asHash())->toBe($hash)
        ->and(EventDatum::integer(-3)->asInteger())->toBe(-3)
        ->and(EventDatum::boolean(false)->asBoolean())->toBeFalse()
        ->and(EventDatum::time($time)->asTime()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-01T08:00:00.500000+00:00')
        ->and(EventDatum::enum(RetentionClass::Standard)->asEnumValue())->toBe('standard')
        ->and(EventDatum::enumValue(7)->asEnumValue())->toBe(7)
        ->and(EventDatum::null()->isNull())->toBeTrue()
        ->and(EventDatum::integer(0)->isNull())->toBeFalse()
        ->and(EventDatum::list(EventDatum::integer(1), EventDatum::null())->items())->toEqual([EventDatum::integer(1), EventDatum::null()])
        ->and(EventDatum::list()->items())->toBe([])
        ->and(EventDatum::integer(1)->kind)->toBe(DatumKind::Integer);
});

it('refuses to give a datum back as another kind', function (EventDatum $datum, string $read, DatumKind $expected): void {
    expect(static fn (): mixed => $datum->{$read}())
        ->toThrow(InvalidEvent::class, sprintf('The event datum is of kind %s, not %s.', $datum->kind->value, $expected->value));
})->with([
    'an integer as an id' => [EventDatum::integer(1), 'asIdentifier', DatumKind::Identifier],
    'an id as a hash' => [EventDatum::identifier(new EventIdentifier('e')), 'asHash', DatumKind::Hash],
    'an enum value as an integer' => [EventDatum::enumValue(1), 'asInteger', DatumKind::Integer],
    'an integer as a boolean' => [EventDatum::integer(1), 'asBoolean', DatumKind::Boolean],
    'null as a time' => [EventDatum::null(), 'asTime', DatumKind::Time],
    'an integer as an enum value' => [EventDatum::integer(1), 'asEnumValue', DatumKind::Enum],
    'an integer as a list' => [EventDatum::integer(1), 'items', DatumKind::List],
]);

it('refuses an enum value that is text', function (string $value): void {
    expect(static fn (): EventDatum => EventDatum::enumValue($value))->toThrow(InvalidEvent::class, sprintf(
        'The string value of an enum case in an event is a word of 1 to 63 letters, digits, "_", ".", ":" or "-"; got a string of %d bytes that is not. An event never carries text (PRD 6.5 invariant 10).',
        strlen($value),
    ));
})->with(['A title', '', '-leading', str_repeat('a', 64), 'æ']);

it('takes enum values that are words', function (string $value): void {
    expect(EventDatum::enumValue($value)->asEnumValue())->toBe($value);
})->with(['released', 'Open', 'a', 'x.y:z-1_2', str_repeat('a', 63)]);

it('keeps the fields of event data sorted by name, each once', function (): void {
    $data = EventData::empty()
        ->with('version', EventDatum::integer(2))
        ->with('entry', EventDatum::identifier(new EventIdentifier('e-1')))
        ->with('after_2', EventDatum::null());

    expect(array_keys($data->fields()))->toBe(['after_2', 'entry', 'version'])
        ->and($data->get('version')->asInteger())->toBe(2)
        ->and($data->has('entry'))->toBeTrue()
        ->and($data->has('title'))->toBeFalse()
        ->and(EventData::empty()->fields())->toBe([])
        ->and(static fn (): EventData => $data->with('entry', EventDatum::null()))->toThrow(InvalidEvent::class, 'The event data already has a field "entry".')
        ->and(static fn (): EventDatum => $data->get('title'))->toThrow(InvalidEvent::class, 'The event data has no field "title".');
});

it('refuses a field name that is not snake_case', function (string $name): void {
    expect(static fn (): EventData => EventData::empty()->with($name, EventDatum::null()))
        ->toThrow(InvalidEvent::class, 'A field of event data is named in snake_case, at most 63 characters.');
})->with(['Title', 'a b', '', '1st', 'a.b', str_repeat('a', 64)]);

it('orders positions by transaction id, then event id', function (): void {
    $position = new EventPosition(10, 5);

    expect($position->isAfter(new EventPosition(9, 50)))->toBeTrue()
        ->and($position->isAfter(new EventPosition(10, 4)))->toBeTrue()
        ->and($position->isAfter(new EventPosition(10, 5)))->toBeFalse()
        ->and($position->isAfter(new EventPosition(11, 1)))->toBeFalse()
        ->and($position->equals(new EventPosition(10, 5)))->toBeTrue()
        ->and($position->equals(new EventPosition(10, 6)))->toBeFalse()
        ->and(EventPosition::start())->toEqual(new EventPosition(0, 0))
        ->and($position->isAfter(EventPosition::start()))->toBeTrue();
});

it('refuses a position below 0', function (int $xid, int $eventId): void {
    expect(static fn (): EventPosition => new EventPosition($xid, $eventId))
        ->toThrow(InvalidEvent::class, sprintf('An event position has a transaction id and an event id of 0 or more, got (%d, %d).', $xid, $eventId));
})->with([[-1, 0], [0, -1]]);

it('has the two streams of PRD 7.5', function (): void {
    expect(array_map(static fn (EventStream $stream): string => $stream->value, EventStream::cases()))->toBe(['interactive', 'bulk']);
});
