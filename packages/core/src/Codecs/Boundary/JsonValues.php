<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Domain\DecimalNumber;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Codecs\Domain\PortableText;
use Cbox\Cms\Core\Codecs\Domain\TextFormats;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use stdClass;

/**
 * The values of the generated codecs (GUARDRAILS 2.2): what a codec reads from a decoded JSON
 * document and how it writes a value back, so the JSON form of every kind of value is fixed in one
 * place and a codec only says which fields a contract version has and what their rules are.
 *
 * Reading, each value in its canonical form:
 *
 * - text is a string, its length counted in characters, as Postgres' char_length() counts it;
 * - an integer is a JSON integer, never a float or a string;
 * - a decimal is a string, such as "12.50", never a JSON number, so no digit is lost to a float,
 *   and it comes back with exactly its field's scale of digits after the point;
 * - a date is `YYYY-MM-DD`, and a date-time is RFC 3339 with an offset, read as the instant it
 *   names and kept in UTC; a date-time is written in UTC with six decimals, such as
 *   `2026-01-01T12:00:00.000000Z`;
 * - an id is its canonical string, and an enum its backing value;
 * - a list is a JSON array, and a missing optional field is left out, never written as null.
 *
 * Every rule a field's blueprint sets (PRD 11.12) is a named argument, such as `maxLength: 120`. A
 * value that breaks one throws DecodingFailed with json_invalid and the path of the value.
 */
#[Experimental]
final readonly class JsonValues
{
    private const string DATE = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/';

    /**
     * A date-time of RFC 3339: the date and the time without the fraction, and the offset, whose
     * hours are 00 to 23.
     */
    private const string DATETIME = '/\A([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.[0-9]{1,6})?(Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/';

    /** The start of a date in year zero, which Postgres does not have. */
    private const string YEAR_ZERO = '0000-';

    private const string DATETIME_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** The longest key of an object in any JSON, as MapEntry allows it. */
    private const int MAX_KEY_BYTES = 255;

    /** A key that can be a segment of a FieldPath. */
    private const string NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * The fields of the object $value, by key, after checking that it is an object with no key
     * outside $keys.
     *
     * @param  ?FieldPath  $path  the object's path, or null for the document itself
     * @param  list<string>  $keys  the keys of the contract
     * @return array<string, mixed>
     *
     * @throws DecodingFailed with json_malformed for a document that is not an object, and with
     *                        json_invalid for a nested value that is not one or for an unknown key
     */
    public static function object(mixed $value, ?FieldPath $path, array $keys): array
    {
        if (! $value instanceof stdClass) {
            throw $path instanceof FieldPath
                ? DecodingFailed::invalid($path, 'is not an object')
                : DecodingFailed::malformed('the document is not a JSON object');
        }

        $fields = [];

        foreach (get_object_vars($value) as $key => $field) {
            $key = (string) $key;

            if (! in_array($key, $keys, true)) {
                throw DecodingFailed::invalid($path, sprintf('has the key "%s", which is not a field of the contract', $key));
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    /**
     * The value of a field that must be present and not null.
     *
     * @template T
     *
     * @param  array<string, mixed>  $object
     * @param  Closure(mixed, FieldPath): T  $read
     * @return T
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function required(array $object, string $key, ?FieldPath $path, Closure $read): mixed
    {
        $at = self::at($path, $key);

        if (! array_key_exists($key, $object)) {
            throw DecodingFailed::invalid($at, 'is missing, and the field is required');
        }

        if ($object[$key] === null) {
            throw DecodingFailed::invalid($at, 'is null, and the field is required');
        }

        return $read($object[$key], $at);
    }

    /**
     * The value of an optional field: Omitted when it is missing, null when it is null.
     *
     * @template T
     *
     * @param  array<string, mixed>  $object
     * @param  Closure(mixed, FieldPath): T  $read
     * @return T|Omitted|null
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function nullable(array $object, string $key, ?FieldPath $path, Closure $read): mixed
    {
        if (! array_key_exists($key, $object)) {
            return Omitted::Field;
        }

        return $object[$key] === null ? null : $read($object[$key], self::at($path, $key));
    }

    /**
     * The value of a required field classified $classification (PRD 12.2), read with $access: as
     * required() when the access allows the classification, and otherwise Omitted, because the
     * caller may not see the field, and a value given for it is refused.
     *
     * @template T
     *
     * @param  array<string, mixed>  $object
     * @param  Closure(mixed, FieldPath): T  $read
     * @return T|Omitted
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function requiredClassified(array $object, string $key, ?FieldPath $path, ClassificationAccess $classification, ClassificationAccess $access, Closure $read): mixed
    {
        return self::allowed($object, $key, $path, $classification, $access)
            ? self::required($object, $key, $path, $read)
            : Omitted::Field;
    }

    /**
     * The value of an optional field classified $classification (PRD 12.2), read with $access: as
     * nullable() when the access allows the classification, and otherwise Omitted, because the
     * caller may not see the field, and a value given for it is refused.
     *
     * @template T
     *
     * @param  array<string, mixed>  $object
     * @param  Closure(mixed, FieldPath): T  $read
     * @return T|Omitted|null
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function nullableClassified(array $object, string $key, ?FieldPath $path, ClassificationAccess $classification, ClassificationAccess $access, Closure $read): mixed
    {
        return self::allowed($object, $key, $path, $classification, $access)
            ? self::nullable($object, $key, $path, $read)
            : Omitted::Field;
    }

    /**
     * @throws DecodingFailed with json_invalid
     */
    public static function text(mixed $value, FieldPath $at, ?int $minLength = null, ?int $maxLength = null, ?string $format = null): string
    {
        if (! is_string($value)) {
            throw DecodingFailed::invalid($at, 'is not a string');
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($minLength !== null && $length < $minLength) {
            throw DecodingFailed::invalid($at, sprintf('has %d characters, fewer than the %d the field requires', $length, $minLength));
        }

        if ($maxLength !== null && $length > $maxLength) {
            throw DecodingFailed::invalid($at, sprintf('has %d characters, more than the %d the field allows', $length, $maxLength));
        }

        if ($format !== null && ! TextFormats::matches($format, $value)) {
            throw DecodingFailed::invalid($at, sprintf('is not in the format %s', $format));
        }

        return $value;
    }

    /**
     * @throws DecodingFailed with json_invalid
     */
    public static function integer(mixed $value, FieldPath $at, ?int $min = null, ?int $max = null): int
    {
        if (! is_int($value)) {
            throw DecodingFailed::invalid($at, 'is not an integer');
        }

        if ($min !== null && $value < $min) {
            throw DecodingFailed::invalid($at, sprintf('is %d, less than the minimum %d', $value, $min));
        }

        if ($max !== null && $value > $max) {
            throw DecodingFailed::invalid($at, sprintf('is %d, more than the maximum %d', $value, $max));
        }

        return $value;
    }

    /**
     * A decimal of a column numeric($precision, $scale), in its canonical form.
     *
     * @param  ?string  $min  a decimal number, as the blueprint writes it
     * @param  ?string  $max  a decimal number, as the blueprint writes it
     * @return numeric-string
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function decimal(mixed $value, FieldPath $at, int $precision, int $scale, ?string $min = null, ?string $max = null): string
    {
        $number = is_string($value) ? DecimalNumber::parse($value) : null;
        $fixed = $number?->fixed($precision, $scale);

        if (! $number instanceof DecimalNumber || $fixed === null) {
            throw DecodingFailed::invalid($at, sprintf('is not a decimal number in a string with at most %d digits before the point and %d after it', $precision - $scale, $scale));
        }

        if ($min !== null && $number->compare(self::bound($min)) < 0) {
            throw DecodingFailed::invalid($at, sprintf('is %s, less than the minimum %s', $fixed, $min));
        }

        if ($max !== null && $number->compare(self::bound($max)) > 0) {
            throw DecodingFailed::invalid($at, sprintf('is %s, more than the maximum %s', $fixed, $max));
        }

        return $fixed;
    }

    /**
     * @throws DecodingFailed with json_invalid
     */
    public static function boolean(mixed $value, FieldPath $at): bool
    {
        if (! is_bool($value)) {
            throw DecodingFailed::invalid($at, 'is not a boolean');
        }

        return $value;
    }

    /**
     * A full date of RFC 3339, `YYYY-MM-DD`, as midnight UTC of that day.
     *
     * @param  ?string  $min  a date, as the blueprint writes it
     * @param  ?string  $max  a date, as the blueprint writes it
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function date(mixed $value, FieldPath $at, ?string $min = null, ?string $max = null): DateTimeImmutable
    {
        $date = is_string($value) && preg_match(self::DATE, $value) === 1 && ! str_starts_with($value, self::YEAR_ZERO)
            ? self::parsed($value)
            : null;

        // PHP rolls a day out of range over into the next month; such a date does not read back.
        if (! is_string($value) || ! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw DecodingFailed::invalid($at, 'is not a date in the form YYYY-MM-DD');
        }

        if ($min !== null && $value < $min) {
            throw DecodingFailed::invalid($at, sprintf('is %s, before the minimum %s', $value, $min));
        }

        if ($max !== null && $value > $max) {
            throw DecodingFailed::invalid($at, sprintf('is %s, after the maximum %s', $value, $max));
        }

        return $date;
    }

    /**
     * A date-time of RFC 3339 with its offset, such as `2026-01-01T13:00:00+01:00`, as the instant
     * it names in UTC. At most six decimals: Postgres keeps microseconds.
     *
     * @param  ?string  $min  a date-time of RFC 3339, as the blueprint writes it
     * @param  ?string  $max  a date-time of RFC 3339, as the blueprint writes it
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function datetime(mixed $value, FieldPath $at, ?string $min = null, ?string $max = null): DateTimeImmutable
    {
        $instant = is_string($value) ? self::instant($value) : null;

        if (! $instant instanceof DateTimeImmutable) {
            throw DecodingFailed::invalid($at, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z');
        }

        if ($min !== null && $instant < self::instantBound($min)) {
            throw DecodingFailed::invalid($at, sprintf('is %s, before the minimum %s', self::encodeDatetime($instant), $min));
        }

        if ($max !== null && $instant > self::instantBound($max)) {
            throw DecodingFailed::invalid($at, sprintf('is %s, after the maximum %s', self::encodeDatetime($instant), $max));
        }

        return $instant;
    }

    /**
     * One of the values of a select field.
     *
     * @template T of string
     *
     * @param  non-empty-list<T>  $choices
     * @return T
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function choice(mixed $value, FieldPath $at, array $choices): string
    {
        foreach ($choices as $choice) {
            if ($value === $choice) {
                return $choice;
            }
        }

        throw DecodingFailed::invalid($at, sprintf('is not one of %s', implode(', ', $choices)));
    }

    /**
     * A list, each item read by $item at its index.
     *
     * @template T
     *
     * @param  Closure(mixed, FieldPath): T  $item
     * @param  bool  $distinct  whether no item may equal another
     * @return list<T>
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function list(mixed $value, FieldPath $at, Closure $item, ?int $minItems = null, ?int $maxItems = null, bool $distinct = false): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw DecodingFailed::invalid($at, 'is not a list');
        }

        $count = count($value);

        if ($minItems !== null && $count < $minItems) {
            throw DecodingFailed::invalid($at, sprintf('has %d items, fewer than the %d the field requires', $count, $minItems));
        }

        if ($maxItems !== null && $count > $maxItems) {
            throw DecodingFailed::invalid($at, sprintf('has %d items, more than the %d the field allows', $count, $maxItems));
        }

        $items = [];

        foreach ($value as $index => $raw) {
            $read = $item($raw, $at->then($index));

            if ($distinct && in_array($read, $items, true)) {
                throw DecodingFailed::invalid($at->then($index), 'is an item the list already has');
            }

            $items[] = $read;
        }

        return $items;
    }

    /**
     * A rich text document of Portable Text blocks (PRD 11.10), with the styles, decorator marks,
     * list kinds and link kinds the field allows.
     *
     * @param  list<string>  $styles
     * @param  list<string>  $marks
     * @param  list<string>  $lists
     * @param  list<string>  $links
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function portableText(mixed $value, FieldPath $at, array $styles, array $marks, array $lists, array $links): ListValue
    {
        $document = self::fieldValue($value, $at);

        if (! $document instanceof ListValue) {
            throw DecodingFailed::invalid($at, 'is not a list of blocks');
        }

        PortableText::check($document, $at, $styles, $marks, $lists, $links);

        return $document;
    }

    /**
     * Any JSON value as a field value: a string as TextValue, an integer as IntegerValue, a boolean
     * as BooleanValue, null as NullValue, an array as ListValue and an object as MapValue. A JSON
     * number with a fraction or out of the integer range has no field value and is refused, as is a
     * key of an object that is empty or longer than 255 bytes.
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function fieldValue(mixed $value, FieldPath $at): FieldValue
    {
        return match (true) {
            is_string($value) => new TextValue($value),
            is_int($value) => new IntegerValue($value),
            is_bool($value) => new BooleanValue($value),
            $value === null => new NullValue,
            is_array($value) => new ListValue(...array_map(
                static fn (mixed $item, int $index): FieldValue => self::fieldValue($item, $at->then($index)),
                $value,
                array_keys($value),
            )),
            $value instanceof stdClass => new MapValue(...array_map(
                static fn (int|string $key, mixed $entry): MapEntry => self::entry((string) $key, $entry, $at),
                array_keys(get_object_vars($value)),
                get_object_vars($value),
            )),
            default => throw DecodingFailed::invalid($at, 'holds a number that is not an integer'),
        };
    }

    /**
     * An id, parsed from its canonical string by $parse, which throws InvalidArgumentException for
     * a string that is not one, as the ids of the contracts do.
     *
     * @template T of object
     *
     * @param  Closure(string): T  $parse
     * @return T
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function id(mixed $value, FieldPath $at, Closure $parse): object
    {
        if (! is_string($value)) {
            throw DecodingFailed::invalid($at, 'is not an id in a string');
        }

        try {
            return $parse($value);
        } catch (InvalidArgumentException $exception) {
            throw DecodingFailed::invalid($at, 'is not a valid id: '.$exception->getMessage(), $exception);
        }
    }

    /**
     * A case of a backed enum, by its value, compared strictly: a string-backed enum takes a string
     * and an int-backed enum an integer.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function enum(mixed $value, FieldPath $at, string $enum): BackedEnum
    {
        foreach ($enum::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw DecodingFailed::invalid($at, sprintf('is not one of %s', implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))));
    }

    /**
     * The canonical form of a decimal of a column numeric($precision, $scale).
     *
     * @throws EncodingFailed when $value is not such a decimal
     */
    public static function encodeDecimal(string $value, int $precision, int $scale): string
    {
        $fixed = DecimalNumber::parse($value)?->fixed($precision, $scale);

        if ($fixed === null) {
            throw EncodingFailed::because(sprintf('"%s" is not a decimal number of precision %d and scale %d', $value, $precision, $scale));
        }

        return $fixed;
    }

    /**
     * A date as `YYYY-MM-DD`, the day it has in its own time zone.
     */
    public static function encodeDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d');
    }

    /**
     * A date-time as the instant in UTC with six decimals, such as `2026-01-01T12:00:00.000000Z`.
     */
    public static function encodeDatetime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT);
    }

    /**
     * A field value as JSON: the reverse of fieldValue().
     *
     * @throws EncodingFailed for a field value that fieldValue() never gives, such as a DecimalValue
     */
    public static function encodeFieldValue(FieldValue $value): mixed
    {
        return match (true) {
            $value instanceof TextValue => $value->value,
            $value instanceof IntegerValue => $value->value,
            $value instanceof BooleanValue => $value->value,
            $value instanceof NullValue => null,
            $value instanceof ListValue => array_map(self::encodeFieldValue(...), $value->items),
            $value instanceof MapValue => self::encodeMap($value),
            default => throw EncodingFailed::because(sprintf('a rich text value holds a %s, which has no JSON form there', $value::class)),
        };
    }

    /**
     * The path of the field $key of the object at $path.
     */
    public static function at(?FieldPath $path, string $key): FieldPath
    {
        return $path instanceof FieldPath ? $path->then($key) : new FieldPath($key);
    }

    /**
     * Whether $access allows the classification; a value given for a field it does not allow is
     * refused.
     *
     * @param  array<string, mixed>  $object
     *
     * @throws DecodingFailed with json_invalid
     */
    private static function allowed(array $object, string $key, ?FieldPath $path, ClassificationAccess $classification, ClassificationAccess $access): bool
    {
        if ($access->allows($classification)) {
            return true;
        }

        if (array_key_exists($key, $object)) {
            throw DecodingFailed::invalid(self::at($path, $key), sprintf('is classified %s, above the classification access %s', $classification->value, $access->value));
        }

        return false;
    }

    /**
     * An entry of an object in any JSON, at the path of its key when the key is a name.
     *
     * @throws DecodingFailed with json_invalid for a key that a MapEntry cannot have
     */
    private static function entry(string $key, mixed $value, FieldPath $at): MapEntry
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_BYTES) {
            throw DecodingFailed::invalid($at, sprintf('has a key of %d bytes; a key has 1 to %d', strlen($key), self::MAX_KEY_BYTES));
        }

        return new MapEntry($key, self::fieldValue($value, preg_match(self::NAME, $key) === 1 ? $at->then($key) : $at));
    }

    private static function encodeMap(MapValue $value): stdClass
    {
        $object = new stdClass;

        foreach ($value->entries as $entry) {
            $object->{$entry->key} = self::encodeFieldValue($entry->value);
        }

        return $object;
    }

    /**
     * The instant a date-time of RFC 3339 names, in UTC, or null when $value is not one.
     */
    private static function instant(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::DATETIME, $value, $parts) !== 1 || str_starts_with($value, self::YEAR_ZERO)) {
            return null;
        }

        $instant = self::parsed($value);

        // PHP rolls a day, hour, minute or second out of range over into the next one; a value
        // that does not read back as it was written names no real time.
        if (! $instant instanceof DateTimeImmutable || $instant->format('Y-m-d\TH:i:s') !== $parts[1] || $instant->format('P') !== ($parts[2] === 'Z' ? '+00:00' : $parts[2])) {
            return null;
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The time a date or a date-time of RFC 3339 names, a date as midnight UTC, or null when PHP
     * cannot read it.
     */
    private static function parsed(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * A bound from a blueprint, which cms:generate has checked.
     */
    private static function bound(string $value): DecimalNumber
    {
        return DecimalNumber::parse($value) ?? throw EncodingFailed::because(sprintf('the bound "%s" is not a decimal number', $value));
    }

    private static function instantBound(string $value): DateTimeImmutable
    {
        return self::instant($value) ?? throw EncodingFailed::because(sprintf('the bound "%s" is not a date-time of RFC 3339', $value));
    }
}
