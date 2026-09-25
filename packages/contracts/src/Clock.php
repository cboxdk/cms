<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * The clock (GUARDRAILS 2.3). Code that needs the current time asks a Clock and never calls
 * `new DateTimeImmutable()`, `time()` or `now()` itself, so tests control time with the testkit's
 * FakeClock.
 *
 * The clock reads the wall clock. It is not monotonic: the system clock can step back, for example
 * when NTP corrects it, so two readings may go backwards. Code that needs ordering, such as the id
 * generator, handles a step back itself.
 *
 * The shared contract suite is the testkit's ClockContractTestCase. Every implementation runs it.
 */
#[Experimental]
interface Clock
{
    /**
     * The current time: an immutable value in the UTC time zone, with microseconds.
     */
    public function now(): DateTimeImmutable;
}
