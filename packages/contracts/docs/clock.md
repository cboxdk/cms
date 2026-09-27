# Clock

<!-- extension-point: Cbox\Cms\Contracts\Clock -->
<!-- extension-point: Cbox\Cms\Testkit\Clock\ClockContract -->

`Cbox\Cms\Contracts\Clock`, in `cboxdk/cms-contracts`, is where the kernel, addons and applications get the current time (GUARDRAILS 2.3). It has one method, `now(): DateTimeImmutable`. The container binds it as a singleton to the class configured in `cbox-cms.contracts`; the default is `Cbox\Cms\Core\Clock\Adapter\SystemClock` in `cboxdk/cms-core`, which reads the system's wall clock. Because the time comes from a contract, a test sets it with the testkit's `FakeClock`, and time is deterministic in tests.

## What now() returns

- **UTC.** The time zone is `UTC` with offset 0, whatever the default time zone of the PHP process is. Convert to a local time zone only where a person reads the value.
- **Microseconds.** The value keeps microseconds; a clock that rounds to whole seconds or milliseconds breaks the contract.
- **Immutable.** It is a `DateTimeImmutable`, so `add()`, `modify()` and `setTimezone()` return a copy and never change the value another class holds.
- **Not monotonic.** It is the wall clock, and the wall clock can step back, for example when NTP corrects it. Two readings may therefore go backwards, and the difference between them is not a duration. Do not order events or measure elapsed time with two readings: the [id generator](id-generator.md) orders ids and handles a clock that steps back itself, and `hrtime(true)` measures a duration.

## Only a clock reads the system clock

Code that needs the time asks for a `Clock` in its constructor and calls `now()`. It never calls `new DateTimeImmutable()`, `time()`, `microtime()`, `date()`, Laravel's `now()` helper or `Carbon::now()`. Only a class that implements `Clock` reads the system clock, so every other class follows the clock that the container, or a test, gives it.

## Replacing the clock

An application replaces the clock in its own `config/cbox-cms.php`, one entry at a time: `'contracts' => [Clock::class => StagingClock::class]`. The core merges its defaults under the application's configuration when its service provider registers, so every entry the application leaves out keeps its default. The container builds the configured class the first time something resolves `Clock` and keeps that instance for the process. The class must implement `Clock` and be instantiable; otherwise resolving `Clock` fails with a message that names the setting.

The container builds the class, so its constructor may ask for anything the container can give. The clock below runs a fixed interval ahead of real time, for a staging environment that shows what scheduled work will do. It takes the interval in its constructor, so the application gives it one with a contextual binding in a service provider's `register()`:

<!-- example-file: examples/Contract/Clock/StagingClock.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Clock;

use Cbox\Cms\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The system clock moved forward by a fixed interval, for a staging environment that runs ahead
 * of real time to show what scheduled work will do. It is a clock implementation, so it is the one
 * place that reads the system clock.
 */
final readonly class StagingClock implements Clock
{
    public function __construct(private DateInterval $ahead) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->add($this->ahead);
    }
}
```

A test boots the application with the replacement where the application's configuration would be. Testbench calls `defineEnvironment()` after the service providers register and before they boot, so before anything resolves the clock. By then the core has merged its defaults into `cbox-cms.contracts`, so the test case sets the single entry `cbox-cms.contracts.<contract>`; setting the whole `cbox-cms.contracts` array there would drop the other defaults. Setting the entry inside a test, after the application has booted, is too late for anything that resolved the clock while booting.

<!-- example-file: examples/Unit/Clock/StagingApplicationTestCase.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Clock;

use Cbox\Cms\Contracts\Clock;
use DateInterval;
use Examples\Contract\Clock\StagingClock;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * Boots an application whose configuration replaces the Clock and nothing else. The packages load
 * through package discovery, as in an installed application.
 */
abstract class StagingApplicationTestCase extends TestCase
{
    #[Override]
    protected $enablesPackageDiscoveries = true;

    /**
     * Runs after the service providers register and before they boot, so before anything resolves
     * the Clock. The core has merged its defaults into cbox-cms.contracts by then, so the test sets the
     * one entry and the others keep their defaults, as with an application's config/cbox-cms.php.
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('cbox-cms.contracts.'.Clock::class, StagingClock::class);

        // What an application does in a service provider's register(): the container builds the
        // clock, so it gives the clock its constructor argument.
        $app->when(StagingClock::class)
            ->needs(DateInterval::class)
            ->give(static fn (): DateInterval => new DateInterval('P1D'));
    }
}
```

The test resolves `Clock` from the container and gets the staging clock, once per process. The id generator, which keeps its default, reads the time from it:

<!-- example: examples/Unit/Clock/ReplaceClockTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use DateTimeImmutable;
use Examples\Contract\Clock\StagingClock;
use PHPUnit\Framework\Attributes\Test;

final class ReplaceClockTest extends StagingApplicationTestCase
{
    #[Test]
    public function the_container_gives_the_configured_clock_once_per_process(): void
    {
        self::assertSame(app(Clock::class), app(Clock::class));
        self::assertGreaterThan(new DateTimeImmutable('+23 hours'), app(Clock::class)->now());
        self::assertInstanceOf(StagingClock::class, app(Clock::class));
    }

    #[Test]
    public function the_other_contracts_keep_their_defaults_and_read_the_time_from_it(): void
    {
        $before = app(Clock::class)->now();
        $id = app(IdGenerator::class)->next();

        self::assertGreaterThanOrEqual(Uuid7::unixMillisecondsOf($before), $id->unixMilliseconds());
    }
}
```

## Testing an implementation

Every implementation of `Clock` runs the shared contract suite, the trait `Cbox\Cms\Testkit\Clock\ClockContract` in `cboxdk/cms-testkit` (GUARDRAILS 2.3). The testkit's `FakeClock` and the core's `SystemClock` run it too, so a fake that behaves differently from the real clock fails the same cases.

Use the trait in a PHPUnit test class in your package's `tests/Contract` directory and return a new instance of your clock from `clock()`. The class may extend any test case, so a clock that needs the application can extend the Laravel test case. The suite checks that `now()` is in UTC, also when the default time zone is another, that the value is immutable, and that it keeps microseconds. It has no case that compares two readings, because the contract does not promise monotonic time.

<!-- example: examples/Contract/Clock/StagingClockContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\ClockContract;
use DateInterval;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Clock suite against the application's own clock. The trait brings the cases; the
 * class only says how to make the clock.
 */
final class StagingClockContractTest extends TestCase
{
    use ClockContract;

    #[Override]
    protected function clock(): Clock
    {
        return new StagingClock(new DateInterval('P1D'));
    }
}
```

## Testing code that reads the time

`Cbox\Cms\Testkit\Clock\FakeClock` is the clock for tests. It starts at `FakeClock::START`, 2026-01-01 00:00:00.123456 UTC, or at the time given to its constructor, and returns the same instant until the test moves it. The start has microseconds on purpose, so code that drops them shows up in tests. Every value is converted to UTC.

- `set($time)` moves the clock to any instant, also backwards, to test code that must survive a clock that steps back.
- `advance($interval)` moves it forwards by exactly the interval, and throws `InvalidArgumentException` for an interval that would move it back.
- `freeze()` stops it at the system's current time and returns that instant.

Each returns the new time. Give the fake to the class under test, or bind it in the container with `app()->instance(Clock::class, $clock)` so that every class the container builds after that gets it.

<!-- example: examples/Unit/Clock/FakeClockTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Clock;
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
    $before = new DateTimeImmutable;

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
```
