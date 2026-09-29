<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Tests\Codecs\Fixtures\Rank;
use Cbox\Cms\Core\Tests\Codecs\Fixtures\Shade;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/*
 * The values of the generated codecs (GUARDRAILS 2.2): each kind of value has one JSON form, every
 * rule of a blueprint is checked when a value is read, and a value that breaks one is refused with
 * json_invalid and the path of the value.
 */

/**
 * The code, the path and the reason of reading $value at `field`.
 *
 * @param  Closure(mixed, FieldPath): mixed  $read
 * @return array{string, ?string, string}
 */
function refusedValue(Closure $read, mixed $value): array
{
    return Failures::described(static fn (): mixed => $read($value, new FieldPath('field')));
}

/**
 * The decoded value of a JSON text.
 */
function json(string $value): mixed
{
    return JsonText::decode('{"v":'.$value.'}')->v;
}

describe('objects and fields', function (): void {
    it('gives the fields of an object by key', function (): void {
        expect(JsonValues::object(JsonText::decode('{"a":1,"b":null}'), null, ['a', 'b', 'c']))->toBe(['a' => 1, 'b' => null]);
    });

    it('refuses a value that is not an object, as malformed at the top and as invalid below it', function (): void {
        expect(Failures::described(static fn (): array => JsonValues::object([], null, ['a'])))
            ->toBe(['json_malformed', null, 'the document is not a JSON object'])
            ->and(Failures::described(static fn (): array => JsonValues::object('x', new FieldPath('group'), ['a'])))
            ->toBe(['json_invalid', 'group', 'is not an object']);
    });

    it('refuses a key the contract does not have, on the object that has it', function (): void {
        expect(Failures::described(static fn (): array => JsonValues::object(JsonText::decode('{"a":1,"z-z":2}'), null, ['a'])))
            ->toBe(['json_invalid', null, 'has the key "z-z", which is not a field of the contract'])
            ->and(Failures::described(static fn (): array => JsonValues::object(JsonText::decode('{"0":1}'), new FieldPath('group', 3), ['a'])))
            ->toBe(['json_invalid', 'group[3]', 'has the key "0", which is not a field of the contract']);
    });

    it('reads a required field, and refuses one that is missing or null', function (): void {
        $read = static fn (mixed $value, FieldPath $at): string => $at->toString().'='.JsonValues::integer($value, $at);

        expect(JsonValues::required(['a' => 1], 'a', null, $read))->toBe('a=1')
            ->and(JsonValues::required(['a' => 1], 'a', new FieldPath('group'), $read))->toBe('group.a=1')
            ->and(Failures::described(static fn (): string => JsonValues::required([], 'a', new FieldPath('group'), $read)))
            ->toBe(['json_invalid', 'group.a', 'is missing, and the field is required'])
            ->and(Failures::described(static fn (): string => JsonValues::required(['a' => null], 'a', null, $read)))
            ->toBe(['json_invalid', 'a', 'is null, and the field is required']);
    });

    it('reads an optional field as Omitted when missing and null when null', function (): void {
        $read = static fn (mixed $value, FieldPath $at): string => $at->toString().'='.JsonValues::integer($value, $at);

        expect(JsonValues::nullable([], 'a', null, $read))->toBe(Omitted::Field)
            ->and(JsonValues::nullable(['a' => null], 'a', null, $read))->toBeNull()
            ->and(JsonValues::nullable(['a' => 2], 'a', new FieldPath('group'), $read))->toBe('group.a=2');
    });

    it('reads a classified field only when the access allows it, and refuses one given above the access', function (): void {
        $read = static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at);

        expect(JsonValues::requiredClassified(['a' => 1], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Internal, $read))->toBe(1)
            ->and(JsonValues::requiredClassified(['a' => 1], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Sensitive, $read))->toBe(1)
            ->and(JsonValues::requiredClassified([], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Public, $read))->toBe(Omitted::Field)
            ->and(JsonValues::nullableClassified([], 'a', null, ClassificationAccess::Confidential, ClassificationAccess::Internal, $read))->toBe(Omitted::Field)
            ->and(JsonValues::nullableClassified([], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Internal, $read))->toBe(Omitted::Field)
            ->and(JsonValues::nullableClassified(['a' => null], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Internal, $read))->toBeNull()
            ->and(Failures::described(static fn (): int|Omitted => JsonValues::requiredClassified([], 'a', null, ClassificationAccess::Internal, ClassificationAccess::Internal, $read)))
            ->toBe(['json_invalid', 'a', 'is missing, and the field is required'])
            ->and(Failures::described(static fn (): int|Omitted => JsonValues::requiredClassified(['a' => 1], 'a', new FieldPath('ext'), ClassificationAccess::Internal, ClassificationAccess::Public, $read)))
            ->toBe(['json_invalid', 'ext.a', 'is classified internal, above the classification access public'])
            ->and(Failures::described(static fn (): int|Omitted|null => JsonValues::nullableClassified(['a' => null], 'a', null, ClassificationAccess::Confidential, ClassificationAccess::Internal, $read)))
            ->toBe(['json_invalid', 'a', 'is classified confidential, above the classification access internal']);
    });

    it('gives the path of a field below an object, or of a field of the document', function (): void {
        expect(JsonValues::at(null, 'a')->toString())->toBe('a')
            ->and(JsonValues::at(new FieldPath('group', 2), 'a')->toString())->toBe('group[2].a');
    });
});

describe('text', function (): void {
    it('reads text and counts its length in characters', function (): void {
        expect(JsonValues::text('æøå', new FieldPath('f'), minLength: 3, maxLength: 3))->toBe('æøå')
            ->and(JsonValues::text('', new FieldPath('f')))->toBe('')
            ->and(JsonValues::text('a@example.org', new FieldPath('f'), format: 'email'))->toBe('a@example.org')
            ->and(JsonValues::text('https://example.org/a?b=c', new FieldPath('f'), format: 'url'))->toBe('https://example.org/a?b=c')
            ->and(JsonValues::text('HTTP://EXAMPLE.ORG', new FieldPath('f'), format: 'url'))->toBe('HTTP://EXAMPLE.ORG');
    });

    it('refuses text that breaks a rule', function (mixed $value, ?int $min, ?int $max, ?string $format, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, $min, $max, $format), $value))
            ->toBe(['json_invalid', 'field', $reason]);
    })->with([
        'a number' => [1, null, null, null, 'is not a string'],
        'null' => [null, null, null, null, 'is not a string'],
        'too short' => ['æø', 3, null, null, 'has 2 characters, fewer than the 3 the field requires'],
        'too long' => ['æøåæ', null, 3, null, 'has 4 characters, more than the 3 the field allows'],
        'not an email address' => ['a@', null, null, 'email', 'is not in the format email'],
        'not a URL' => ['example.org', null, null, 'url', 'is not in the format url'],
        'a URL of another scheme' => ['ftp://example.org', null, null, 'url', 'is not in the format url'],
        'a scheme without a host' => ['http://', null, null, 'url', 'is not in the format url'],
        'a scheme that only starts like one' => ['https-like://example.org', null, null, 'url', 'is not in the format url'],
        'an unknown format' => ['a', null, null, 'colour', 'is not in the format colour'],
    ]);
});

describe('numbers and flags', function (): void {
    it('reads integers within their bounds', function (): void {
        expect(JsonValues::integer(json('5'), new FieldPath('f'), min: 5, max: 5))->toBe(5)
            ->and(JsonValues::integer(json('-9223372036854775808'), new FieldPath('f')))->toBe(PHP_INT_MIN);
    });

    it('refuses integers that break a rule', function (string $value, ?int $min, ?int $max, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, $min, $max), json($value)))
            ->toBe(['json_invalid', 'field', $reason]);
    })->with([
        'a float' => ['1.0', null, null, 'is not an integer'],
        'a string' => ['"1"', null, null, 'is not an integer'],
        'beyond 64 bits' => ['9223372036854775808', null, null, 'is not an integer'],
        'below the minimum' => ['0', 1, null, 'is 0, less than the minimum 1'],
        'above the maximum' => ['11', null, 10, 'is 11, more than the maximum 10'],
    ]);

    it('reads decimals in a string, with exactly the scale of their column', function (): void {
        expect(JsonValues::decimal('12.5', new FieldPath('f'), 10, 2))->toBe('12.50')
            ->and(JsonValues::decimal('0.00', new FieldPath('f'), 10, 2, min: '0', max: '0.0'))->toBe('0.00')
            ->and(JsonValues::decimal('99999999.99', new FieldPath('f'), 10, 2, max: '99999999.99'))->toBe('99999999.99');
    });

    it('refuses decimals that break a rule', function (string $value, ?string $min, ?string $max, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): string => JsonValues::decimal($value, $at, 5, 2, $min, $max), json($value)))
            ->toBe(['json_invalid', 'field', $reason]);
    })->with([
        'a JSON number' => ['1.5', null, null, 'is not a decimal number in a string with at most 3 digits before the point and 2 after it'],
        'an exponent' => ['"1e2"', null, null, 'is not a decimal number in a string with at most 3 digits before the point and 2 after it'],
        'too many digits before the point' => ['"1000"', null, null, 'is not a decimal number in a string with at most 3 digits before the point and 2 after it'],
        'a digit past the scale' => ['"1.005"', null, null, 'is not a decimal number in a string with at most 3 digits before the point and 2 after it'],
        'below the minimum' => ['"-0.01"', '0', null, 'is -0.01, less than the minimum 0'],
        'above the maximum' => ['"10.5"', null, '10.49', 'is 10.50, more than the maximum 10.49'],
    ]);

    it('reads booleans and nothing else', function (): void {
        expect(JsonValues::boolean(false, new FieldPath('f')))->toBeFalse()
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): bool => JsonValues::boolean($value, $at), 0))
            ->toBe(['json_invalid', 'field', 'is not a boolean']);
    });
});

describe('dates and times', function (): void {
    it('reads a date as midnight UTC of the day', function (): void {
        $date = JsonValues::date('2024-02-29', new FieldPath('f'), min: '2024-02-29', max: '2024-02-29');

        expect($date->format(DATE_ATOM))->toBe('2024-02-29T00:00:00+00:00')
            ->and($date->getTimezone()->getName())->toBe('UTC');
    });

    it('refuses dates that break a rule', function (mixed $value, ?string $min, ?string $max, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): DateTimeImmutable => JsonValues::date($value, $at, $min, $max), $value))
            ->toBe(['json_invalid', 'field', $reason]);
    })->with([
        'not text' => [20240101, null, null, 'is not a date in the form YYYY-MM-DD'],
        'a date-time' => ['2024-01-01T00:00:00Z', null, null, 'is not a date in the form YYYY-MM-DD'],
        'a day that does not exist' => ['2023-02-29', null, null, 'is not a date in the form YYYY-MM-DD'],
        'day 32' => ['2024-01-32', null, null, 'is not a date in the form YYYY-MM-DD'],
        'month 13' => ['2024-13-01', null, null, 'is not a date in the form YYYY-MM-DD'],
        'day zero' => ['2024-01-00', null, null, 'is not a date in the form YYYY-MM-DD'],
        'year zero' => ['0000-01-01', null, null, 'is not a date in the form YYYY-MM-DD'],
        'a short year' => ['24-01-01', null, null, 'is not a date in the form YYYY-MM-DD'],
        'before the minimum' => ['1999-12-31', '2000-01-01', null, 'is 1999-12-31, before the minimum 2000-01-01'],
        'after the maximum' => ['2100-01-01', null, '2099-12-31', 'is 2100-01-01, after the maximum 2099-12-31'],
    ]);

    it('reads a date-time with its offset as the instant in UTC', function (string $value, string $utc): void {
        $instant = JsonValues::datetime($value, new FieldPath('f'));

        expect($instant->getTimezone()->getName())->toBe('UTC')
            ->and(JsonValues::encodeDatetime($instant))->toBe($utc);
    })->with([
        'in UTC' => ['2026-01-01T12:00:00Z', '2026-01-01T12:00:00.000000Z'],
        'with an offset' => ['2026-01-01T13:30:00+01:30', '2026-01-01T12:00:00.000000Z'],
        'a negative offset over midnight' => ['2025-12-31T23:00:00-02:00', '2026-01-01T01:00:00.000000Z'],
        'microseconds' => ['2026-01-01T12:00:00.123456Z', '2026-01-01T12:00:00.123456Z'],
        'fewer decimals' => ['2026-01-01T12:00:00.5Z', '2026-01-01T12:00:00.500000Z'],
    ]);

    it('refuses date-times that break a rule', function (mixed $value, ?string $min, ?string $max, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): DateTimeImmutable => JsonValues::datetime($value, $at, $min, $max), $value))
            ->toBe(['json_invalid', 'field', $reason]);
    })->with([
        'not text' => [1, null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'without an offset' => ['2026-01-01T12:00:00', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'a date' => ['2026-01-01', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'seven decimals' => ['2026-01-01T12:00:00.1234567Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'a lowercase t' => ['2026-01-01t12:00:00Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'hour 24' => ['2026-01-01T24:00:00Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'minute 60' => ['2026-01-01T12:60:00Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'second 60' => ['2026-01-01T12:00:60Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'an offset of 24 hours' => ['2026-01-01T12:00:00+24:00', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'an offset of 60 minutes' => ['2026-01-01T12:00:00+01:60', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'a day that does not exist' => ['2026-02-30T12:00:00Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'year zero' => ['0000-01-01T00:00:00Z', null, null, 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
        'before the minimum' => ['2000-01-01T00:59:59+01:00', '2000-01-01T00:00:00Z', null, 'is 1999-12-31T23:59:59.000000Z, before the minimum 2000-01-01T00:00:00Z'],
        'after the maximum' => ['2000-01-01T00:00:00.000001Z', null, '2000-01-01T01:00:00+01:00', 'is 2000-01-01T00:00:00.000001Z, after the maximum 2000-01-01T01:00:00+01:00'],
    ]);

    it('accepts a date-time on its bounds', function (): void {
        expect(JsonValues::encodeDatetime(JsonValues::datetime('2000-01-01T01:00:00+01:00', new FieldPath('f'), '2000-01-01T00:00:00Z', '2000-01-01T00:00:00Z')))
            ->toBe('2000-01-01T00:00:00.000000Z');
    });

    it('writes a date as its day in its own time zone', function (): void {
        expect(JsonValues::encodeDate(new DateTimeImmutable('2026-03-01T23:30:00', new DateTimeZone('Europe/Copenhagen'))))->toBe('2026-03-01');
    });
});

describe('choices, lists, ids and enums', function (): void {
    it('reads one of the choices and refuses anything else', function (): void {
        expect(JsonValues::choice('b', new FieldPath('f'), ['a', 'b']))->toBe('b')
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): string => JsonValues::choice($value, $at, ['a', 'b']), 'c'))
            ->toBe(['json_invalid', 'field', 'is not one of a, b'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): string => JsonValues::choice($value, $at, ['1']), 1))
            ->toBe(['json_invalid', 'field', 'is not one of 1']);
    });

    it('reads a list, each item at its index', function (): void {
        $paths = [];
        $items = JsonValues::list(['a', 'b'], new FieldPath('f'), static function (mixed $value, FieldPath $at) use (&$paths): string {
            $paths[] = $at->toString();

            return JsonValues::text($value, $at);
        }, minItems: 2, maxItems: 2, distinct: true);

        expect($items)->toBe(['a', 'b'])
            ->and($paths)->toBe(['f[0]', 'f[1]']);
    });

    it('refuses lists that break a rule', function (string $value, ?int $min, ?int $max, bool $distinct, string $path, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, JsonValues::text(...), $min, $max, $distinct), json($value)))
            ->toBe(['json_invalid', $path, $reason]);
    })->with([
        'an object' => ['{"0":"a"}', null, null, false, 'field', 'is not a list'],
        'text' => ['"a"', null, null, false, 'field', 'is not a list'],
        'too few items' => ['["a"]', 2, null, false, 'field', 'has 1 items, fewer than the 2 the field requires'],
        'too many items' => ['["a","b","c"]', null, 2, false, 'field', 'has 3 items, more than the 2 the field allows'],
        'an item twice' => ['["a","b","a"]', null, null, true, 'field[2]', 'is an item the list already has'],
        'an item of the wrong kind' => ['["a",1]', null, null, false, 'field[1]', 'is not a string'],
    ]);

    it('allows an item twice unless the list is distinct', function (): void {
        expect(JsonValues::list(['a', 'a'], new FieldPath('f'), JsonValues::text(...)))->toBe(['a', 'a']);
    });

    it('reads an id from its string, and refuses what does not parse', function (): void {
        $id = '0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20';

        expect(JsonValues::id($id, new FieldPath('f'), EntryId::fromString(...))->toString())->toBe($id)
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): EntryId => JsonValues::id($value, $at, EntryId::fromString(...)), 12))
            ->toBe(['json_invalid', 'field', 'is not an id in a string']);

        $failure = Failures::of(static fn (): EntryId => JsonValues::id('not-a-uuid', new FieldPath('f'), EntryId::fromString(...)));

        expect($failure->errorCode)->toBe(ErrorCode::JsonInvalid)
            ->and($failure->reason)->toStartWith('is not a valid id: ')
            ->and($failure->getPrevious())->toBeInstanceOf(InvalidUuid7::class);
    });

    it('reads a case of a backed enum by its value', function (): void {
        expect(JsonValues::enum('dark', new FieldPath('f'), Shade::class))->toBe(Shade::Dark)
            ->and(JsonValues::enum(2, new FieldPath('f'), Rank::class))->toBe(Rank::Second)
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): Shade => JsonValues::enum($value, $at, Shade::class), 'grey'))
            ->toBe(['json_invalid', 'field', 'is not one of light, dark'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): Rank => JsonValues::enum($value, $at, Rank::class), 1.0))
            ->toBe(['json_invalid', 'field', 'is not one of 1, 2'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): Rank => JsonValues::enum($value, $at, Rank::class), 3))
            ->toBe(['json_invalid', 'field', 'is not one of 1, 2'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): Rank => JsonValues::enum($value, $at, Rank::class), '1'))
            ->toBe(['json_invalid', 'field', 'is not one of 1, 2'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): Shade => JsonValues::enum($value, $at, Shade::class), 1))
            ->toBe(['json_invalid', 'field', 'is not one of light, dark']);
    });
});

describe('rich text and field values', function (): void {
    it('reads any JSON as field values, objects sorted by key, and writes it back', function (): void {
        $value = JsonValues::fieldValue(json('[{"b":1,"a":[true,null,"x"],"-":{}}]'), new FieldPath('f'));

        expect($value->equals(new ListValue(new MapValue(
            new MapEntry('-', new MapValue),
            new MapEntry('a', new ListValue(new BooleanValue(true), new NullValue, new TextValue('x'))),
            new MapEntry('b', new IntegerValue(1)),
        ))))->toBeTrue();

        $object = new stdClass;
        $object->v = JsonValues::encodeFieldValue($value);

        expect(JsonText::encode($object))->toBe('{"v":[{"-":{},"a":[true,null,"x"],"b":1}]}');
    });

    it('refuses a number with a fraction and a key a map cannot have, at the path of the value', function (string $value, string $path, string $reason): void {
        expect(refusedValue(static fn (mixed $value, FieldPath $at): mixed => JsonValues::fieldValue($value, $at), json($value)))
            ->toBe(['json_invalid', $path, $reason]);
    })->with([
        'a fraction below a name' => ['{"a":[1.5]}', 'field.a[0]', 'holds a number that is not an integer'],
        'a fraction below a key that is no name' => ['{"a-b":{"c":1.5}}', 'field.c', 'holds a number that is not an integer'],
        'the empty key' => ['{"":1}', 'field', 'has a key of 0 bytes; a key has 1 to 255'],
        'a number below a key of 255 bytes' => ['{"'.str_repeat('k', 255).'":1.5}', 'field.'.str_repeat('k', 255), 'holds a number that is not an integer'],
        'a key of 256 bytes' => ['{"'.str_repeat('k', 256).'":1}', 'field', 'has a key of 256 bytes; a key has 1 to 255'],
    ]);

    it('reads rich text as a list of blocks that keep the rules', function (): void {
        $blocks = json('[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":"x","marks":["em"]}]}]');

        expect(JsonValues::portableText($blocks, new FieldPath('f'), ['normal'], ['em'], [], [])->items)->toHaveCount(1)
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): ListValue => JsonValues::portableText($value, $at, ['normal'], [], [], []), $blocks))
            ->toBe(['json_invalid', 'field[0].children[0].marks[0]', 'is neither a decorator the field allows (none) nor the key of a mark definition of the block'])
            ->and(refusedValue(static fn (mixed $value, FieldPath $at): ListValue => JsonValues::portableText($value, $at, ['normal'], [], [], []), json('{"a":1}')))
            ->toBe(['json_invalid', 'field', 'is not a list of blocks']);
    });

    it('reads a key that is a number as text', function (): void {
        $value = JsonValues::fieldValue(json('{"1":true}'), new FieldPath('f'));

        expect($value->equals(new MapValue(new MapEntry('1', new BooleanValue(true)))))->toBeTrue();
    });

    it('writes no field value that reading never gives', function (): void {
        expect(static fn (): mixed => JsonValues::encodeFieldValue(new ListValue(new DecimalValue('1.5'))))
            ->toThrow(EncodingFailed::class, 'a rich text value holds a Cbox\Cms\Contracts\Fields\DecimalValue, which has no JSON form there');
    });
});

describe('bounds', function (): void {
    it('refuses a bound that cms:generate would never write, as a codec built wrong', function (): void {
        expect(static fn (): string => JsonValues::decimal('1', new FieldPath('f'), 5, 2, min: 'one'))
            ->toThrow(EncodingFailed::class, 'the bound "one" is not a decimal number')
            ->and(static fn (): string => JsonValues::decimal('1', new FieldPath('f'), 5, 2, max: '1e2'))
            ->toThrow(EncodingFailed::class, 'the bound "1e2" is not a decimal number')
            ->and(static fn (): DateTimeImmutable => JsonValues::datetime('2026-01-01T00:00:00Z', new FieldPath('f'), min: '2026-01-01'))
            ->toThrow(EncodingFailed::class, 'the bound "2026-01-01" is not a date-time of RFC 3339')
            ->and(static fn (): DateTimeImmutable => JsonValues::datetime('2026-01-01T00:00:00Z', new FieldPath('f'), max: 'tomorrow'))
            ->toThrow(EncodingFailed::class, 'the bound "tomorrow" is not a date-time of RFC 3339');
    });
});

describe('writing', function (): void {
    it('writes a decimal with exactly the scale of its column', function (): void {
        expect(JsonValues::encodeDecimal('7', 5, 2))->toBe('7.00')
            ->and(JsonValues::encodeDecimal('-0012.5000', 5, 1))->toBe('-12.5')
            ->and(static fn (): string => JsonValues::encodeDecimal('1.005', 5, 2))
            ->toThrow(EncodingFailed::class, '"1.005" is not a decimal number of precision 5 and scale 2')
            ->and(static fn (): string => JsonValues::encodeDecimal('ten', 5, 2))
            ->toThrow(EncodingFailed::class, '"ten" is not a decimal number of precision 5 and scale 2');
    });
});
