<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Ids;

use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\Uuid7;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The UUIDv7 value object: parsing, the typed exception, the range helpers for partition bounds
 * and the step rule in generate(). The generators' shared behaviour is in the IdGenerator contract
 * suite.
 */

const V7 = '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f';

/** The last millisecond a UUIDv7 can hold. */
const LAST_MS = 0xFFFF_FFFF_FFFF;

function seeded(int $seed = 1): Randomizer
{
    return new Randomizer(new Xoshiro256StarStar($seed));
}

/** The 42-bit counter of an id: rand_a and the first 30 bits of rand_b (RFC 9562, 6.2, method 1). */
function counterOf(Uuid7 $id): int
{
    $hex = str_replace('-', '', $id->value);

    return (intval(substr($hex, 13, 3), 16) << 30) | (intval(substr($hex, 16, 8), 16) & 0x3FFF_FFFF);
}

it('parses a UUIDv7 and keeps the canonical string', function (): void {
    $id = new Uuid7(V7);

    expect($id->value)->toBe(V7)
        ->and($id->unixMilliseconds())->toBe(0x01936F5E8A2B);
});

it('stores upper case hex in lower case', function (): void {
    $id = new Uuid7(strtoupper(V7));

    expect($id->value)->toBe(V7)
        ->and($id->equals(new Uuid7(V7)))->toBeTrue();
});

it('accepts every variant digit of RFC 9562', function (string $digit): void {
    $value = substr_replace(V7, $digit, 19, 1);

    expect(new Uuid7($value)->value)->toBe($value);
})->with(['8', '9', 'a', 'b']);

it('rejects a UUID of another version with a typed exception', function (string $value, string $version): void {
    expect(fn (): Uuid7 => new Uuid7($value))
        ->toThrow(InvalidUuid7::class, "is a UUID version {$version}, not version 7");
})->with([
    'v4' => ['9b2e6f1e-8c1d-4b7a-9f3e-2d4c5b6a7e8f', '4'],
    'v1' => ['c232ab00-9414-11ec-b3c8-9f6bdeced846', '1'],
    'v6' => ['1ec9414c-232a-6b00-b3c8-9f6bdeced846', '6'],
    'v8' => ['01936f5e-8a2b-8c3d-9e4f-5a6b7c8d9e0f', '8'],
    'nil' => ['00000000-0000-0000-0000-000000000000', '0'],
    'max' => ['ffffffff-ffff-ffff-ffff-ffffffffffff', 'f'],
]);

it('rejects a version 7 digit without the RFC 9562 variant', function (string $digit): void {
    expect(fn (): Uuid7 => new Uuid7(substr_replace(V7, $digit, 19, 1)))
        ->toThrow(InvalidUuid7::class, 'does not have the RFC 9562 variant');
})->with(['0', '7', 'c', 'f']);

it('rejects malformed input with a typed exception', function (string $value): void {
    expect(fn (): Uuid7 => new Uuid7($value))->toThrow(InvalidUuid7::class, 'Expected a UUID in the form');
})->with([
    'empty' => '',
    'words' => 'not-a-uuid',
    'no hyphens' => '01936f5e8a2b7c3d9e4f5a6b7c8d9e0f',
    'one short' => '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0',
    'one long' => '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f0',
    'not hex' => '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0g',
    'hyphens moved' => '01936f5e8-a2b-7c3d-9e4f-5a6b7c8d9e0f',
    'braces' => '{01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f}',
    'urn' => 'urn:uuid:01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f',
    'trailing newline' => V7."\n",
    'leading space' => ' '.V7,
    'null byte' => V7."\0",
]);

it('is an InvalidArgumentException that shows cut and escaped input', function (): void {
    try {
        new Uuid7(str_repeat('x', 100)."\n");
    } catch (InvalidUuid7 $invalid) {
        expect($invalid)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($invalid->getMessage())->toContain(str_repeat('x', 64).'...')
            ->and($invalid->getMessage())->not->toContain(str_repeat('x', 65));

        return;
    }

    Assert::fail('Expected InvalidUuid7.');
});

it('orders ids as their strings', function (): void {
    $low = new Uuid7('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');
    $high = new Uuid7('01936f5e-8a2c-7000-8000-000000000000');

    expect($low->compareTo($high))->toBeLessThan(0)
        ->and($high->compareTo($low))->toBeGreaterThan(0)
        ->and($low->compareTo(new Uuid7($low->value)))->toBe(0)
        ->and($low->equals($high))->toBeFalse();
});

it('builds the lowest and highest id of a millisecond', function (): void {
    $ms = 0x01936F5E8A2B;

    expect(Uuid7::lowestAt($ms)->value)->toBe('01936f5e-8a2b-7000-8000-000000000000')
        ->and(Uuid7::highestAt($ms)->value)->toBe('01936f5e-8a2b-7fff-bfff-ffffffffffff')
        ->and(Uuid7::lowestAt($ms)->unixMilliseconds())->toBe($ms)
        ->and(Uuid7::highestAt($ms)->unixMilliseconds())->toBe($ms)
        ->and(Uuid7::lowestAt(0)->value)->toBe('00000000-0000-7000-8000-000000000000')
        ->and(Uuid7::highestAt(LAST_MS)->value)->toBe('ffffffff-ffff-7fff-bfff-ffffffffffff');
});

it('puts every id of a millisecond between its lowest and highest id, and below the next millisecond', function (): void {
    $random = seeded();
    $ms = 1_767_225_600_123;

    for ($i = 0; $i < 1000; $i++) {
        $id = Uuid7::generate($ms, $random);

        expect(Uuid7::lowestAt($ms)->compareTo($id))->toBeLessThanOrEqual(0)
            ->and(Uuid7::highestAt($ms)->compareTo($id))->toBeGreaterThanOrEqual(0);
    }

    expect(Uuid7::highestAt($ms)->compareTo(Uuid7::lowestAt($ms + 1)))->toBeLessThan(0)
        ->and(Uuid7::highestAt($ms - 1)->compareTo(Uuid7::lowestAt($ms)))->toBeLessThan(0);
});

it('refuses a time a UUIDv7 cannot hold', function (int $ms): void {
    expect(fn (): Uuid7 => Uuid7::lowestAt($ms))->toThrow(InvalidUuid7::class, 'holds a unix time from 0')
        ->and(fn (): Uuid7 => Uuid7::highestAt($ms))->toThrow(InvalidUuid7::class, 'holds a unix time from 0')
        ->and(fn (): Uuid7 => Uuid7::generate($ms, seeded()))->toThrow(InvalidUuid7::class, 'holds a unix time from 0');
})->with([-1, LAST_MS + 1, PHP_INT_MIN, PHP_INT_MAX]);

it('reads the unix milliseconds of an instant, rounded down', function (string $instant, int $ms): void {
    expect(Uuid7::unixMillisecondsOf(new DateTimeImmutable($instant)))->toBe($ms);
})->with([
    ['1970-01-01T00:00:00.000000+00:00', 0],
    ['1970-01-01T00:00:00.000999+00:00', 0],
    ['1970-01-01T00:00:00.001000+00:00', 1],
    ['2026-01-01T00:00:00.123456+00:00', 1_767_225_600_123],
    ['2026-01-01T01:00:00.123456+01:00', 1_767_225_600_123],
    ['1969-12-31T23:59:59.999500+00:00', -1],
    ['1969-12-31T23:59:59.500000+00:00', -500],
]);

it('starts the counter of a new millisecond with its top bit clear', function (): void {
    for ($seed = 0; $seed < 500; $seed++) {
        $id = Uuid7::generate(1_767_225_600_123, seeded($seed));

        expect(counterOf($id))->toBeLessThan(1 << 41);
    }
});

it('uses the given time when it is after the last id', function (): void {
    $random = seeded();
    $first = Uuid7::generate(1000, $random);
    $second = Uuid7::generate(1001, $random, $first);

    expect($second->unixMilliseconds())->toBe(1001)
        ->and($second->compareTo($first))->toBeGreaterThan(0);
});

it('increments the counter by one when the time repeats or steps back', function (int $ms): void {
    $random = seeded();
    $first = Uuid7::generate(5000, $random);
    $second = Uuid7::generate($ms, $random, $first);

    expect($second->unixMilliseconds())->toBe(5000)
        ->and(counterOf($second))->toBe(counterOf($first) + 1)
        ->and($second->compareTo($first))->toBeGreaterThan(0);
})->with([5000, 4999, 0]);

it('moves to the next millisecond when the counter is full', function (): void {
    $full = Uuid7::highestAt(7000);
    $next = Uuid7::generate(7000, seeded(), $full);

    expect(counterOf($full))->toBe((1 << 42) - 1)
        ->and($next->unixMilliseconds())->toBe(7001)
        ->and(counterOf($next))->toBeLessThan(1 << 41)
        ->and($next->compareTo($full))->toBeGreaterThan(0);
});

it('refuses to go past the last millisecond', function (): void {
    expect(fn (): Uuid7 => Uuid7::generate(LAST_MS, seeded(), Uuid7::highestAt(LAST_MS)))
        ->toThrow(InvalidUuid7::class, 'There is no UUIDv7 after');
});

it('reads back the counter it wrote, through every bit of it', function (): void {
    $random = seeded(7);
    $previous = Uuid7::generate(9000, $random);

    for ($i = 0; $i < 2000; $i++) {
        $next = Uuid7::generate(9000, $random, $previous);

        expect(counterOf($next))->toBe(counterOf($previous) + 1)
            ->and(new Uuid7($next->value)->equals($next))->toBeTrue();

        $previous = $next;
    }

    // A counter that carries through the variant digit and into rand_a.
    $carry = new Uuid7('00000000-2328-7000-bfff-ffffffffffff');
    $after = Uuid7::generate(9000, $random, $carry);

    expect(counterOf($carry))->toBe((1 << 30) - 1)
        ->and(substr($after->value, 14, 9))->toBe('7001-8000')
        ->and($after->compareTo($carry))->toBeGreaterThan(0);
});
