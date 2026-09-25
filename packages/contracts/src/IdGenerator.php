<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\Uuid7;

/**
 * The id generator (GUARDRAILS 2.3, PRD 5.3). Code that creates an aggregate asks an IdGenerator
 * for its id and wraps the Uuid7 in the typed id, so tests get deterministic ids from the
 * testkit's FakeIdGenerator.
 *
 * Every implementation takes the time part from the Clock contract, and ids from one instance are
 * strictly increasing: each id sorts after the one before it, also when the clock repeats a
 * millisecond or steps back. Ids from different instances are only ordered by their milliseconds.
 *
 * The shared contract suite is the testkit's IdGeneratorContract. Every implementation runs it.
 */
#[Experimental]
interface IdGenerator
{
    /**
     * A new UUIDv7 that sorts after every id this instance returned before.
     */
    public function next(): Uuid7;
}
