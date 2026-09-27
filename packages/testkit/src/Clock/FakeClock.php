<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Clock;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * A clock that only moves when a test moves it (GUARDRAILS 2.3).
 *
 * It starts at START, or at the time given to the constructor, and returns the same instant until
 * set(), advance() or freeze() changes it. Every value is converted to UTC and keeps its
 * microseconds. START has microseconds on purpose, so code that drops them shows up in tests.
 *
 * set() can move the clock backwards, to test code that must survive a wall clock that steps back.
 * advance() only moves forwards.
 *
 * It is also a PSR-20 clock, as the core's SystemClock is, so a test gives the same fake to a
 * library that asks for Psr\Clock\ClockInterface.
 */
#[Experimental]
final class FakeClock implements Clock, ClockInterface
{
    /** The instant a new FakeClock starts at. */
    public const string START = '2026-01-01T00:00:00.123456+00:00';

    private DateTimeImmutable $now;

    public function __construct(?DateTimeInterface $now = null)
    {
        $this->now = $this->utc($now ?? new DateTimeImmutable(self::START));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Stops the clock at the system's current time and returns that instant.
     */
    public function freeze(): DateTimeImmutable
    {
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->now;
    }

    /**
     * Moves the clock to the given instant, forwards or backwards.
     */
    public function set(DateTimeInterface $now): DateTimeImmutable
    {
        $this->now = $this->utc($now);

        return $this->now;
    }

    /**
     * Moves the clock forwards by exactly the given interval.
     */
    public function advance(DateInterval $interval): DateTimeImmutable
    {
        $next = $this->now->add($interval);

        if ($next < $this->now) {
            throw new InvalidArgumentException('advance() only moves the clock forwards. Use set() to move it back.');
        }

        $this->now = $next;

        return $this->now;
    }

    private function utc(DateTimeInterface $time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }
}
