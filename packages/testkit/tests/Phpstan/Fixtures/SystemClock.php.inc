<?php

declare(strict_types=1);

// Every form of reading the system clock, reported outside a Clock implementation and tests,
// each next to the same function or class used without reading the clock, which is not.

namespace Fixture\SystemClock\Domain {
    use Carbon\Carbon;
    use Carbon\CarbonImmutable;
    use Carbon\FactoryImmutable;
    use DateTime;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Support\Carbon as LaravelCarbon;
    use Illuminate\Support\DateFactory;
    use Illuminate\Support\Facades\Date;
    use Illuminate\Support\InteractsWithTime;
    use Illuminate\Support\Traits\Macroable;
    use Symfony\Component\Clock\Clock;
    use Symfony\Component\Clock\ClockAwareTrait;
    use Symfony\Component\Clock\DatePoint;
    use Symfony\Component\Clock\MockClock;
    use Symfony\Component\Clock\MonotonicClock;
    use Symfony\Component\Clock\NativeClock;

    final readonly class Readings
    {
        public function functions(): void
        {
            time();
            microtime(true);
            gettimeofday();
            uniqid();
            now();
            today();
            time(...);
            date(...);
            hrtime(true);
        }

        public function timestamps(int $timestamp, ?int $maybe): void
        {
            date('Y-m-d');
            date('Y-m-d', $maybe);
            gmdate('H');
            idate('Y');
            strftime('%Y');
            gmstrftime('%Y');
            getdate();
            localtime();
            mktime(0);
            gmmktime(0, 0, 0, 1, 1, $maybe);
            date('Y-m-d', $timestamp);
            date(format: 'Y', timestamp: $timestamp);
            gmdate('H', $timestamp);
            idate('Y', $timestamp);
            strftime('%Y', $timestamp);
            gmstrftime('%Y', $timestamp);
            getdate($timestamp);
            localtime($timestamp, true);
            mktime(0, 0, 0, 1, 1, 2026);
            gmmktime(12, 0, 0, 1, 1, 2026);
            date(...['Y-m-d', $timestamp]);
        }

        public function strings(string $input, ?string $maybe, bool $early): void
        {
            strtotime('+1 day');
            new DateTimeImmutable();
            new DateTimeImmutable('now', new DateTimeZone('UTC'));
            new DateTime('today');
            new DateTimeImmutable('');
            new DateTimeImmutable(timezone: new DateTimeZone('UTC'));
            new DateTimeImmutable($maybe);
            new DateTimeImmutable($early ? 'first monday of january' : '2026-01-01');
            date_create();
            date_create_immutable('tomorrow');
            strtotime('2026-01-01 00:00:00 UTC');
            strtotime('+1 day', 1767225600);
            strtotime($input);
            new DateTimeImmutable('2026-01-01T10:00:00+02:00');
            new DateTimeImmutable('@1767225600');
            new DateTime('2026-01-01');
            new DateTimeImmutable($input);
            date_create_immutable('2026-01-01');
        }

        public function formats(string $format, string $input): void
        {
            DateTimeImmutable::createFromFormat('Y-m-d', '2026-01-01');
            DateTime::createFromFormat('H:i', '10:00');
            date_create_immutable_from_format('Y-m-d', '2026-01-01');
            date_create_from_format('Y-m-d H:i:\s', '2026-01-01 10:00:s');
            DateTimeImmutable::createFromFormat('!Y-m-d', '2026-01-01');
            DateTimeImmutable::createFromFormat('Y-m-d|', '2026-01-01');
            DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2026-01-01 10:00:00');
            DateTimeImmutable::createFromFormat('U', '1767225600');
            DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-01-01T10:00:00');
            DateTimeImmutable::createFromFormat($format, $input);
            date_create_from_format('!d/m/Y', '01/01/2026');
        }

        public function carbon(Carbon $carbon, CarbonImmutable $other, DateFactory $factory, FactoryImmutable $carbonFactory): void
        {
            Carbon::now();
            Carbon::now(...);
            CarbonImmutable::today();
            LaravelCarbon::tomorrow();
            Carbon::yesterday();
            Carbon::createFromTime(10);
            Carbon::createFromTimeString('10:00');
            Carbon::createFromDate(2026, 1, 1);
            Carbon::create();
            Carbon::createMidnightDate(2026);
            Carbon::parse();
            CarbonImmutable::parse('next monday');
            new Carbon();
            Date::now();
            $factory->now();
            $carbonFactory->parse('now');
            $carbon->isPast();
            $carbon->diffInDays(...);
            $carbon->isToday();
            $carbon->isCurrentMonth();
            $carbon->diffForHumans();
            $carbon->diffInDays();
            $other->ago();
            Carbon::create(2026, 1, 1);
            Carbon::createMidnightDate(2026, 1, 1);
            Carbon::parse('2026-01-01');
            new CarbonImmutable('2026-01-01 10:00:00');
            Carbon::createFromTimestamp(1767225600);
            $carbon->isLastOfMonth();
            $carbon->diffForHumans($other);
            $carbon->diffInDays($other);
            $carbon->isSameDay($other);
        }

        public function server(): void
        {
            $started = $_SERVER['REQUEST_TIME'];
            $precise = $_SERVER['REQUEST_TIME_FLOAT'];
            $uri = $_SERVER['REQUEST_URI'];
        }

        public function symfony(NativeClock $native, MonotonicClock $monotonic, MockClock $mock): void
        {
            \Symfony\Component\Clock\now();
            $native->now();
            $monotonic->now();
            Clock::get();
            new Clock()->now();
            new DatePoint();
            new NativeClock();
            new DatePoint('2026-01-01');
            $mock->now();
        }
    }

    final class Jobs
    {
        use InteractsWithTime;
    }

    final class Reminders
    {
        use ClockAwareTrait;
    }

    final class Reports
    {
        use Macroable;
    }
}

namespace Fixture\SystemClock\Adapter {
    use Cbox\Cms\Contracts\Clock;
    use Cbox\Cms\Contracts\IdGenerator;
    use Cbox\Cms\Contracts\Ids\Uuid7;
    use DateTimeImmutable;
    use DateTimeZone;

    // A Clock implementation reads the system clock.
    final readonly class WallClock implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        public function seconds(): int
        {
            return (static fn (): int => time())();
        }
    }

    // Any other adapter may not, whatever its layer, and an IdGenerator is no clock.
    final readonly class Stamps
    {
        public function stamp(): int
        {
            return time();
        }
    }

    final readonly class TimeIds implements IdGenerator
    {
        public function next(): Uuid7
        {
            return Uuid7::lowestAt((int) (microtime(true) * 1000));
        }
    }
}

namespace Fixture\SystemClock\Tests {
    final class ClockTest
    {
        public function started(): int
        {
            return time();
        }
    }
}

namespace {
    // A Pest file: the global namespace below a tests directory, as this fixture is.
    $started = time();
}
