---
title: Id generator
weight: 33
description: "The IdGenerator contract: UUIDv7 ids ordered by time, how to replace the generator, the shared suite IdGeneratorContract, and the seeded FakeIdGenerator."
---

# Id generator

<!-- extension-point: Cbox\Cms\Contracts\IdGenerator -->
<!-- extension-point: Cbox\Cms\Testkit\Ids\IdGeneratorContract -->

`Cbox\Cms\Contracts\IdGenerator`, in `cboxdk/cms-contracts`, makes the ids of aggregates (GUARDRAILS 2.3, PRD 5.3). It has one method, `next(): Uuid7`. The container binds it as a singleton to the class configured in `cbox-cms.contracts`; the default is `Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator` in `cboxdk/cms-core`, which takes the time from the [Clock](clock.md) and the random bits from the system's secure random source. Postgres 17 has no `uuidv7()`, so ids are made in the application, and because they come from a contract, a test gets the same ids on every run from the testkit's `FakeIdGenerator`.

Code that creates an aggregate asks for an `IdGenerator` in its constructor and wraps the `Uuid7` from `next()` in the typed id, such as `ChangesetId`. It never makes a UUID itself, with `Str::uuid()`, `random_bytes()` or a UUID library. Only a class that implements `IdGenerator` makes one. The testkit's PHPStan rule reports every other place as `cboxCms.uuid`, which no ignore comment can hide: `Str::uuid()`, `Str::uuid7()`, `Str::orderedUuid()` and `Str::ulid()`, the Eloquent traits `HasUuids`, `HasVersion4Uuids` and `HasUlids`, the random and time-based factories of ramsey/uuid and symfony/uid, and `uuid_create()`. Test code is not checked: a namespace with a `Tests` segment, and a file in the global namespace below a `tests` directory, where Pest files live. Migrations, config files and route files are in the global namespace too, and they are checked like any other code. Name-based UUIDs (versions 3 and 5) and parsing an existing UUID make no new id and are not reported.

## What next() returns

`next()` returns a `Cbox\Cms\Contracts\Ids\Uuid7`, a UUID version 7 as RFC 9562 defines it. Its `value` is the canonical string: 36 characters of lowercase hex with hyphens.

- **The time comes first.** The first 48 bits are the Clock's unix time in whole milliseconds, which `unixMilliseconds()` returns and `Uuid7::unixMillisecondsOf($time)` computes for any instant. The strings therefore sort by time, which gives B-tree indexes locality and lets tables be partitioned on ranges of ids (PRD 4.1).
- **Strictly increasing per instance.** Every id sorts after every id the same instance returned before, also when the clock repeats a millisecond or steps back. The generator then keeps the last millisecond it used and counts up within it, until the clock passes that millisecond again; for that while, an id's time is ahead of the clock. The container keeps one instance per process, so ids from one process increase. Ids from different processes are ordered only by their milliseconds.
- **Not before 1970.** A clock before the unix epoch cannot be written in 48 bits, and `next()` throws `InvalidUuid7`, as `new Uuid7()` does for a string that is not a UUIDv7.

## Ranges of ids

`Uuid7::lowestAt($milliseconds)` is the lowest id in a millisecond and `Uuid7::highestAt($milliseconds)` the highest. The ids made from the start of a day to the start of the next are those from `lowestAt()` of the day's first millisecond, inclusive, to `lowestAt()` of the next day's first millisecond, exclusive, or to `highestAt()` of the day's last millisecond, inclusive. The partition manager bounds a partition keyed on UUIDv7 the same way. Compare ids with `compareTo()`, which is the order of the strings.

## Ids are not secrets

An id tells the time it was made to the millisecond, and ids from one generator tell the order they were made in. The random bits keep ids from colliding, not from being guessed. Never use an id as a token, in a link that grants access, or to hide content: check access on every read, and make a secret with `random_bytes()`.

## Replacing the generator

An application replaces the generator in its own `config/cbox-cms.php`, one entry at a time: `'contracts' => [IdGenerator::class => CountingIdGenerator::class]`. The entries it leaves out keep their defaults, and the container builds the configured class the first time something resolves `IdGenerator` and keeps that instance for the process. [Replacing the clock](clock.md#replacing-the-clock) explains the mechanism.

The generator below wraps another generator and counts the ids this process made. It passes every id on unchanged, so it keeps the guarantees of the generator it wraps:

<!-- example-file: examples/Contract/Ids/CountingIdGenerator.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Ids;

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;

/**
 * Wraps another generator and counts the ids this process made, for a metric. It passes every id
 * on unchanged, so the ids keep the order the wrapped generator gives them.
 */
final class CountingIdGenerator implements IdGenerator
{
    private int $made = 0;

    public function __construct(private readonly IdGenerator $inner) {}

    public function next(): Uuid7
    {
        $id = $this->inner->next();
        $this->made++;

        return $id;
    }

    public function made(): int
    {
        return $this->made;
    }
}
```

The test case sets the single entry in `defineEnvironment()`, and gives the counting generator the core's generator to wrap with a contextual binding, as the application does in a service provider's `register()`:

<!-- example-file: examples/Unit/Ids/CountingApplicationTestCase.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Ids;

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Examples\Contract\Ids\CountingIdGenerator;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * Boots an application whose configuration replaces the IdGenerator and nothing else. The packages
 * load through package discovery, as in an installed application.
 */
abstract class CountingApplicationTestCase extends TestCase
{
    #[Override]
    protected $enablesPackageDiscoveries = true;

    /**
     * Runs after the service providers register and before they boot, so before anything resolves
     * the IdGenerator. The core has merged its defaults into cbox-cms.contracts by then, so the test sets
     * the one entry and the others keep their defaults, as with an application's config/cbox-cms.php.
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('cbox-cms.contracts.'.IdGenerator::class, CountingIdGenerator::class);

        // What an application does in a service provider's register(): the counting generator
        // wraps the core's generator, which reads the time from the configured Clock.
        $app->when(CountingIdGenerator::class)
            ->needs(IdGenerator::class)
            ->give(SystemIdGenerator::class);
    }
}
```

The test resolves `IdGenerator` from the container and gets the counting generator, once per process. It counts every id that code gets from the container, and the clock keeps its default:

<!-- example: examples/Unit/Ids/ReplaceIdGeneratorTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Ids;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Examples\Contract\Ids\CountingIdGenerator;
use PHPUnit\Framework\Attributes\Test;

final class ReplaceIdGeneratorTest extends CountingApplicationTestCase
{
    #[Test]
    public function the_container_gives_the_configured_generator_once_per_process(): void
    {
        self::assertSame(app(IdGenerator::class), app(IdGenerator::class));
        self::assertInstanceOf(CountingIdGenerator::class, app(IdGenerator::class));
    }

    #[Test]
    public function it_counts_the_ids_that_code_gets_from_the_container(): void
    {
        $first = app(IdGenerator::class)->next();
        $second = app(IdGenerator::class)->next();
        $generator = app()->get(IdGenerator::class);

        self::assertLessThan(0, $first->compareTo($second));
        self::assertInstanceOf(CountingIdGenerator::class, $generator);
        self::assertSame(2, $generator->made());
    }

    #[Test]
    public function the_clock_keeps_its_default(): void
    {
        self::assertInstanceOf(SystemClock::class, app(Clock::class));
    }
}
```

## Testing an implementation

Every implementation of `IdGenerator` runs the shared contract suite, the trait `Cbox\Cms\Testkit\Ids\IdGeneratorContract` in `cboxdk/cms-testkit` (GUARDRAILS 2.3). The testkit's `FakeIdGenerator` and the core's `SystemIdGenerator` run it too.

Use the trait in a PHPUnit test class in your package's `tests/Contract` directory. `generator(Clock $clock)` returns a new instance of your generator that reads the time from `$clock`: the suite drives the time with a `FakeClock`. It checks the version and variant bits, that the first 48 bits are the clock's milliseconds, that ids are unique and sort in the order they were made while the clock stands still and while it moves, that an id made after the clock steps back sorts after the one before and keeps its millisecond, that ids follow the clock again once it passes that millisecond, and that a time before 1970 is refused. It reads the bits from the string, so a mistake in the layout shows up even when the id parses.

<!-- example: examples/Contract/Ids/CountingIdGeneratorContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Ids;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Testkit\Ids\IdGeneratorContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdGenerator suite against the application's own generator. The suite drives the time
 * with a FakeClock, so the generator, and the one it wraps, must read the time from $clock.
 */
final class CountingIdGeneratorContractTest extends TestCase
{
    use IdGeneratorContract;

    #[Override]
    protected function generator(Clock $clock): IdGenerator
    {
        return new CountingIdGenerator(new SystemIdGenerator($clock));
    }
}
```

## Testing code that makes ids

`Cbox\Cms\Testkit\Ids\FakeIdGenerator` makes ids the way the real generator does, but the random bits come from a seeded engine. Two fakes with the same seed, `FakeIdGenerator::DEFAULT_SEED` or the one given to the constructor, and the same movements of the clock give the same ids. The clock is a new `FakeClock` unless the test gives it one, so a test that moves no clock gets ids in one millisecond with a counter that increases. Give the fake to the class under test, or bind it in the container with `app()->instance(IdGenerator::class, $ids)`.

<!-- example: examples/Unit/Ids/FakeIdGeneratorTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;

// The FakeIdGenerator makes ids the way the real generator does, from a seeded random engine and
// a clock, so a test gets the same ids on every run.

it('gives the same ids for the same seed and the same clock', function (): void {
    $first = new FakeIdGenerator(seed: 7);
    $second = new FakeIdGenerator(seed: 7);
    $otherSeed = new FakeIdGenerator(seed: 8);

    $ids = [$first->next()->value, $first->next()->value];

    expect($ids)->toBe([$second->next()->value, $second->next()->value])
        ->and($otherSeed->next()->value)->not->toBe($ids[0]);
});

it('puts the milliseconds of the clock in the first 48 bits', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-01T12:00:00.250999+00:00'));

    $id = new FakeIdGenerator(clock: $clock)->next();

    expect($id->unixMilliseconds())->toBe(1_772_366_400_250)
        ->and(Uuid7::unixMillisecondsOf($clock->now()))->toBe(1_772_366_400_250)
        ->and(substr(str_replace('-', '', $id->value), 0, 12))->toBe(sprintf('%012x', 1_772_366_400_250));
});

it('keeps ids increasing when the clock steps back', function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(clock: $clock);

    $before = $ids->next();
    $clock->set($clock->now()->modify('-1 minute'));
    $after = $ids->next();

    expect($after->compareTo($before))->toBeGreaterThan(0)
        ->and($after->unixMilliseconds())->toBe($before->unixMilliseconds());
});

it('bounds the ids of one day with lowestAt() and highestAt()', function (): void {
    $day = new DateTimeImmutable('2026-03-01T00:00:00+00:00');
    $nextDay = $day->modify('+1 day');
    $from = Uuid7::lowestAt(Uuid7::unixMillisecondsOf($day));
    $until = Uuid7::lowestAt(Uuid7::unixMillisecondsOf($nextDay));
    $last = Uuid7::highestAt(Uuid7::unixMillisecondsOf($nextDay) - 1);
    $clock = new FakeClock($day);
    $ids = new FakeIdGenerator(clock: $clock);

    foreach (['00:00:00.000000', '12:00:00.000000', '23:59:59.999999'] as $time) {
        $clock->set(new DateTimeImmutable("2026-03-01T{$time}+00:00"));
        $id = $ids->next();

        expect($id->compareTo($from))->toBeGreaterThanOrEqual(0, "At {$time}.")
            ->and($id->compareTo($last))->toBeLessThanOrEqual(0, "At {$time}.")
            ->and($id->compareTo($until))->toBeLessThan(0, "At {$time}.");
    }

    $clock->set($nextDay);

    expect($ids->next()->compareTo($until))->toBeGreaterThanOrEqual(0);
});

it('is the IdGenerator of every class the container builds once it is bound', function (): void {
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 7));

    expect(app(IdGenerator::class)->next()->value)->toBe(new FakeIdGenerator(seed: 7)->next()->value);
});
```
