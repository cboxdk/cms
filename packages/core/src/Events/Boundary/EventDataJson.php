<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Events\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\TextHash;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * The JSON form of an event's data in the `data` column of `events` (PRD 7.2).
 *
 * The data is an object with a member per field, and each value is an object with one member,
 * named by its DatumKind, so a value reads back as the kind it was written as:
 *
 *     {"count": {"integer": 3}, "entry": {"identifier": "0199..."}, "title": {"hash": "9f86..."},
 *      "at": {"time": "2026-04-01T08:00:00.000000Z"}, "state": {"enum": "released"},
 *      "previous": {"null": null}, "tags": {"list": [{"identifier": "a"}, {"identifier": "b"}]}}
 *
 * An instant is UTC with six digits of microseconds. decode() checks every value with the same
 * rules as EventDatum, so a string that is no id, hash or enum word is refused on the way back
 * too.
 */
#[Internal]
final readonly class EventDataJson
{
    public const string TIME_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public static function encode(EventData $data): string
    {
        $fields = [];

        foreach ($data->fields() as $name => $datum) {
            $fields[$name] = self::value($datum);
        }

        return $fields === [] ? '{}' : json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $json): EventData
    {
        try {
            $fields = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidEvent::stored('its data is not JSON.');
        }

        if (! is_array($fields) || (array_is_list($fields) && $fields !== [])) {
            throw InvalidEvent::stored('its data is not a JSON object.');
        }

        $data = EventData::empty();

        foreach ($fields as $name => $value) {
            $data = $data->with((string) $name, self::datum($value));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function value(EventDatum $datum): array
    {
        return [$datum->kind->value => match ($datum->kind) {
            DatumKind::Identifier => $datum->asIdentifier()->value,
            DatumKind::Hash => $datum->asHash()->value,
            DatumKind::Integer => $datum->asInteger(),
            DatumKind::Boolean => $datum->asBoolean(),
            DatumKind::Time => $datum->asTime()->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT),
            DatumKind::Enum => $datum->asEnumValue(),
            DatumKind::Null => null,
            DatumKind::List => array_map(self::value(...), $datum->items()),
        }];
    }

    private static function datum(mixed $value): EventDatum
    {
        if (! is_array($value) || count($value) !== 1) {
            throw InvalidEvent::stored('a value of its data is not an object with one member.');
        }

        $kind = DatumKind::tryFrom((string) array_key_first($value));
        $raw = array_first($value);

        return match ($kind) {
            DatumKind::Identifier => is_string($raw) ? EventDatum::identifier(new EventIdentifier($raw)) : throw self::kind($kind),
            DatumKind::Hash => is_string($raw) ? EventDatum::hash(new TextHash($raw)) : throw self::kind($kind),
            DatumKind::Integer => is_int($raw) ? EventDatum::integer($raw) : throw self::kind($kind),
            DatumKind::Boolean => is_bool($raw) ? EventDatum::boolean($raw) : throw self::kind($kind),
            DatumKind::Time => EventDatum::time(self::time($raw)),
            DatumKind::Enum => is_int($raw) || is_string($raw) ? EventDatum::enumValue($raw) : throw self::kind($kind),
            DatumKind::Null => $raw === null ? EventDatum::null() : throw self::kind($kind),
            DatumKind::List => is_array($raw) && array_is_list($raw) ? EventDatum::list(...array_map(self::datum(...), $raw)) : throw self::kind($kind),
            null => throw InvalidEvent::stored('a value of its data has an unknown kind.'),
        };
    }

    private static function time(mixed $raw): DateTimeImmutable
    {
        $time = is_string($raw) ? DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $raw, new DateTimeZone('UTC')) : false;

        if ($time === false || $time->format(self::TIME_FORMAT) !== $raw) {
            throw self::kind(DatumKind::Time);
        }

        return $time;
    }

    private static function kind(DatumKind $kind): InvalidEvent
    {
        return InvalidEvent::stored(sprintf('a value of kind %s in its data has another type.', $kind->value));
    }
}
