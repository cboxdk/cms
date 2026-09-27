<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/*
 * The FakeClock's own behaviour. The shared contract suite (tests/Contract) checks what every clock
 * promises; these cases check what only the fake promises: it never moves by itself, and it moves
 * exactly as far as a test tells it to.
 */

const PRECISE = 'Y-m-d\TH:i:s.uP';

/** Microseconds since the epoch, exact for every instant after 1970. */
function microsecondsOf(DateTimeImmutable $time): int
{
    return (int) $time->format('Uu');
}

it('starts at a fixed instant in UTC with microseconds', function (): void {
    $now = new FakeClock()->now();

    expect($now->format(PRECISE))->toBe('2026-01-01T00:00:00.123456+00:00')
        ->and($now->format(PRECISE))->toBe(FakeClock::START)
        ->and($now->getTimezone()->getName())->toBe('UTC');
});

it('starts two clocks at the same instant, so tests are deterministic', function (): void {
    expect(new FakeClock()->now()->format(PRECISE))->toBe(new FakeClock()->now()->format(PRECISE));
});

it('returns equal readings until something moves it', function (): void {
    $clock = new FakeClock;

    $first = $clock->now();
    usleep(2000);
    $second = $clock->now();
    $third = $clock->now();

    expect($second)->toEqual($first)
        ->and($third)->toEqual($first)
        ->and(microsecondsOf($second))->toBe(microsecondsOf($first))
        ->and($third->format(PRECISE))->toBe($first->format(PRECISE));
});

it('moves now() by exactly the given interval', function (DateInterval $interval, int $microseconds): void {
    $clock = new FakeClock;
    $before = $clock->now();

    $returned = $clock->advance($interval);
    $after = $clock->now();

    expect(microsecondsOf($after) - microsecondsOf($before))->toBe($microseconds)
        ->and($returned)->toEqual($after)
        ->and($after->getTimezone()->getName())->toBe('UTC');
})->with([
    'nothing' => [new DateInterval('PT0S'), 0],
    'one microsecond' => [DateInterval::createFromDateString('1 usec'), 1],
    '250 microseconds' => [DateInterval::createFromDateString('250 usec'), 250],
    'a carry into the next second' => [DateInterval::createFromDateString('999999 usec'), 999_999],
    'one second' => [new DateInterval('PT1S'), 1_000_000],
    'ninety minutes' => [new DateInterval('PT1H30M'), 5_400_000_000],
    'one day' => [new DateInterval('P1D'), 86_400_000_000],
]);

it('adds successive advances up exactly', function (): void {
    $clock = new FakeClock;
    $start = microsecondsOf($clock->now());

    $clock->advance(DateInterval::createFromDateString('600000 usec'));
    $clock->advance(DateInterval::createFromDateString('600000 usec'));
    $clock->advance(new DateInterval('PT2S'));

    expect(microsecondsOf($clock->now()) - $start)->toBe(3_200_000)
        ->and($clock->now()->format(PRECISE))->toBe('2026-01-01T00:00:03.323456+00:00');
});

it('does not decrease while it is only advanced', function (): void {
    $clock = new FakeClock;
    $previous = $clock->now();

    foreach (['PT0S', 'PT1S', 'PT0S', 'PT59M', 'P1D', 'PT0S'] as $spec) {
        $clock->advance(new DateInterval($spec));
        $current = $clock->now();

        expect($current >= $previous)->toBeTrue("After advancing {$spec}.");

        $previous = $current;
    }
});

it('refuses to advance backwards and keeps its time', function (DateInterval $interval): void {
    $clock = new FakeClock;
    $before = $clock->now();

    expect(fn (): DateTimeImmutable => $clock->advance($interval))->toThrow(InvalidArgumentException::class, 'Use set() to move it back.')
        ->and($clock->now())->toEqual($before);
})->with([
    'an inverted interval' => [(static function (): DateInterval {
        $interval = new DateInterval('PT1S');
        $interval->invert = 1;

        return $interval;
    })()],
    'a negative relative interval' => [DateInterval::createFromDateString('-1 second')],
    'negative microseconds' => [DateInterval::createFromDateString('-1 usec')],
]);

it('sets an instant in UTC and keeps its microseconds', function (string $given, string $expected): void {
    $clock = new FakeClock;

    $returned = $clock->set(new DateTimeImmutable($given));

    expect($clock->now()->format(PRECISE))->toBe($expected)
        ->and($clock->now()->getTimezone()->getName())->toBe('UTC')
        ->and($returned)->toEqual($clock->now());
})->with([
    'an offset' => ['2026-03-29T03:30:00.654321+02:00', '2026-03-29T01:30:00.654321+00:00'],
    'a named zone' => ['2026-10-25 02:30:00.000001 Europe/Copenhagen', '2026-10-25T00:30:00.000001+00:00'],
    'Z' => ['2030-06-01T12:00:00.999999Z', '2030-06-01T12:00:00.999999+00:00'],
]);

it('moves backwards with set(), like a wall clock that steps back', function (): void {
    $clock = new FakeClock;
    $before = $clock->now();

    $clock->set($before->modify('-1 second'));

    expect(microsecondsOf($clock->now()) - microsecondsOf($before))->toBe(-1_000_000);
});

it('copies a mutable time, so changing it later does not move the clock', function (): void {
    $time = new DateTime('2026-05-01T08:00:00.500000+00:00');
    $clock = new FakeClock($time);

    $time->modify('+1 day');
    $clock->set($set = new DateTime('2026-05-02T08:00:00.250000+00:00'));
    $set->modify('+1 day');

    expect($clock->now()->format(PRECISE))->toBe('2026-05-02T08:00:00.250000+00:00');
});

it('starts at a given time, converted to UTC', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-07-01T14:00:00.000042', new DateTimeZone('Europe/Copenhagen')));

    expect($clock->now()->format(PRECISE))->toBe('2026-07-01T12:00:00.000042+00:00')
        ->and($clock->now()->getTimezone()->getName())->toBe('UTC');
});

it('freezes at the system time and then stays there', function (): void {
    $clock = new FakeClock;

    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $frozen = $clock->freeze();
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    usleep(2000);

    expect($frozen >= $before && $frozen <= $after)->toBeTrue()
        ->and($frozen->getTimezone()->getName())->toBe('UTC')
        ->and($clock->now())->toEqual($frozen)
        ->and($clock->now()->format(PRECISE))->toBe($frozen->format(PRECISE));
});

it('is a PSR-20 clock that gives the same instant as its now(), and follows the test', function (): void {
    $clock = new FakeClock;
    $psr = static fn (ClockInterface $clock): DateTimeImmutable => $clock->now();
    $contract = static fn (Clock $clock): DateTimeImmutable => $clock->now();

    expect($clock)->toBeInstanceOf(ClockInterface::class)
        ->and($psr($clock))->toBe($contract($clock))
        ->and($psr($clock)->format(PRECISE))->toBe(FakeClock::START);

    $clock->advance(new DateInterval('PT1S'));

    expect($psr($clock))->toBe($clock->now())
        ->and($psr($clock)->format(PRECISE))->toBe('2026-01-01T00:00:01.123456+00:00');
});
