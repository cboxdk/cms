<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Cbox\Cms\Testkit\Clock\FakeClock;

// The FakeClock only moves when the test moves it. Bind it in the container, and every class that
// asks for a Clock gets it.

it('stands still at its start, with microseconds, until the test moves it', function (): void {
    $clock = new FakeClock;

    expect($clock->now()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-01-01T00:00:00.123456+00:00')
        ->and($clock->now())->toBe($clock->now());
});

it('moves forwards with advance() and to any instant, also back, with set()', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-01T12:00:00.000000+00:00'));

    $clock->advance(new DateInterval('PT90S'));
    expect($clock->now()->format('H:i:s'))->toBe('12:01:30');

    $clock->set(new DateTimeImmutable('2026-03-01T11:00:00.5+01:00'));
    expect($clock->now()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-03-01T10:00:00.500000+00:00');
});

it('refuses to move back with advance()', function (): void {
    $backwards = new DateInterval('PT1S');
    $backwards->invert = 1;

    expect(fn (): DateTimeImmutable => new FakeClock()->advance($backwards))
        ->toThrow(InvalidArgumentException::class, 'Use set() to move it back.');
});

it('stops at the system time with freeze()', function (): void {
    $clock = new FakeClock;
    $before = new SystemClock()->now();

    $frozen = $clock->freeze();

    expect($frozen)->toBeGreaterThanOrEqual($before)
        ->and($clock->now())->toBe($frozen);
});

it('is the Clock of every class the container builds once it is bound', function (): void {
    $clock = new FakeClock;
    app()->instance(Clock::class, $clock);

    $clock->advance(new DateInterval('P1D'));

    expect(app(Clock::class)->now()->format('Y-m-d'))->toBe('2026-01-02');
});
