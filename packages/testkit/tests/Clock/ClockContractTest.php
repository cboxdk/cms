<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Clock;

use Cbox\Cms\Contracts\Clock;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The shared Clock suite must fail a clock that breaks the contract. Each case runs one method of
 * the suite against a broken clock and expects an assertion failure.
 */

function clockCase(Clock $clock): InjectedClockContract
{
    $case = new InjectedClockContract('shared_case');
    $case->subject = $clock;

    return $case;
}

/**
 * @param  Closure(): DateTimeImmutable  $now
 */
function clockReturning(Closure $now): Clock
{
    return new readonly class($now) implements Clock
    {
        /** @param Closure(): DateTimeImmutable $now */
        public function __construct(private Closure $now) {}

        public function now(): DateTimeImmutable
        {
            return ($this->now)();
        }
    };
}

it('passes a clock that keeps the contract', function (): void {
    $case = clockCase(clockReturning(static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))));

    $case->now_is_in_the_utc_time_zone();
    $case->now_is_in_utc_whatever_the_default_time_zone();
    $case->now_is_an_immutable_value();
    $case->now_keeps_microseconds();

    expect(true)->toBeTrue();
});

it('fails a clock in another time zone', function (): void {
    $case = clockCase(clockReturning(static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen'))));

    expect(fn () => $case->now_is_in_the_utc_time_zone())->toThrow(AssertionFailedError::class);
});

it('fails a clock at offset +00:00 instead of the UTC zone', function (): void {
    $case = clockCase(clockReturning(static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('+00:00'))));

    expect(fn () => $case->now_is_in_the_utc_time_zone())->toThrow(AssertionFailedError::class);
});

it('fails a clock that follows the default time zone', function (): void {
    $default = date_default_timezone_get();
    $case = clockCase(clockReturning(static fn (): DateTimeImmutable => new DateTimeImmutable('now')));

    expect(fn () => $case->now_is_in_utc_whatever_the_default_time_zone())->toThrow(AssertionFailedError::class)
        ->and(date_default_timezone_get())->toBe($default);
});

it('fails a clock whose value changes itself', function (): void {
    $case = clockCase(clockReturning(static fn (): DateTimeImmutable => new SelfChangingTime('now', new DateTimeZone('UTC'))));

    expect(fn () => $case->now_is_an_immutable_value())->toThrow(AssertionFailedError::class);
});

it('fails a clock that drops microseconds', function (string $format): void {
    $case = clockCase(clockReturning(static function () use ($format): DateTimeImmutable {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new DateTimeImmutable($now->format($format), new DateTimeZone('UTC'));
    }));

    expect(fn () => $case->now_keeps_microseconds())->toThrow(AssertionFailedError::class, 'dropped its microseconds');
})->with([
    'whole seconds' => ['Y-m-d\TH:i:s'],
    'milliseconds' => ['Y-m-d\TH:i:s.v'],
]);
