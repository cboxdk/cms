---
title: Testing with the testkit
weight: 12
description: Test code that uses the kernel's contracts with the testkit's fakes, pick the right test suite, and run the suites locally.
---

# Testing with the testkit

`cboxdk/cms-testkit` is what the kernel's own tests and an addon's tests are written with. For every contract it has a fake, which behaves like the real implementation and lets the test control it, and a shared suite, which every implementation of the contract runs. Each fake runs the shared suite of its contract too, so a fake that behaves differently from the real implementation fails the same cases.

## The fakes

| Contract | Fake | What the test controls |
|---|---|---|
| `Clock` | `Cbox\Cms\Testkit\Clock\FakeClock` | The time: it stands still until the test calls `set()`, `advance()` or `freeze()`. |
| `IdGenerator` | `Cbox\Cms\Testkit\Ids\FakeIdGenerator` | The ids: the random bits come from a seed, so a run gives the same ids every time. |
| `ReceiptStore` | `Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore` | The receipts, in memory, with sessions that have transactions, and partitions a test can take away with `uncover()`. |
| `IdempotencyStore` | `Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore` | The claims and records, in memory, with sessions, and what happens while a contested claim waits, with `whenWaiting()`. |
| `DoctorCheck` | `Cbox\Cms\Testkit\Doctor\FakeDoctorCheck` | Whether the check passes or fails, and how it fails. |

Give a fake to the class under test, or bind it in the container, for example `app()->instance(Clock::class, $clock)`, so every class the container builds after that gets it. Each contract's page under [Contracts](../addons/contracts/_index.md) shows its fake in a running example. This one is the clock's:

<!-- example: examples/Unit/Clock/FakeClockTest.php -->
```php
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
```

A test that must see what Postgres or Valkey really does, such as locks, grants, row security or key expiry, uses the harnesses `RealPostgres` and `RealValkey` instead of a fake. They are on [Testing against real Postgres and Valkey](../addons/real-services.md).

## The suites

`phpunit.xml` splits the tests into suites by what they need. A package puts a test for a suite in `packages/<package>/tests/<Suite>`; its other tests are in `Unit`.

| Suite | What is in it | Needs |
|---|---|---|
| `Unit` | Tests that need no service, the tooling's tests in `tests/Feature` and the examples in `examples/Unit`. | nothing |
| `Codecs` | JSON documents and schemas, validated with opis/json-schema. | nothing |
| `Contract` | The shared suites of the contracts, one test class per implementation. | nothing, or the services for an implementation on Postgres |
| `Postgres` | Tests on the real Postgres and Valkey, through `RealPostgres` and `RealValkey`. | `composer services:up` |
| `Arch` | The architecture tests that hold the layers. | nothing |
| `Actions` | The tests of the actions, with hand-written fakes of their ports. | nothing |
| `Browser` | Pest browser tests of the workbench in Chromium. Gate 8, run by CI. | Playwright and Chromium |
| `Mutation` | The tests that need a coverage driver. Run by CI next to mutation testing. | PCOV |

Run one suite with `vendor/bin/pest --testsuite=Unit`, and one test file with `vendor/bin/pest <path>`. `composer check` runs the suites of gate 5 one after another and fails a suite with a skipped or incomplete test, so a missing service fails the gate instead of skipping tests.

## Where a test runs

Every checkout gets its own Postgres test database and its own Valkey key prefix, so the suites of two checkouts can run at the same time on the shared services. [Services and isolation](../developers/services.md) has the details.
