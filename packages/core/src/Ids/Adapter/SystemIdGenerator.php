<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Ids\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * UUIDv7 from the Clock and the system's secure random source. The default binding of IdGenerator
 * in the container, as a singleton, so ids from one process increase.
 *
 * Postgres 17 has no uuidv7(), so ids are made in the application (PRD 5.3). The layout and the
 * handling of a clock that repeats or steps back are Uuid7::generate(): the id keeps the last
 * millisecond it used and increments the counter until the clock passes that millisecond again.
 */
#[Experimental]
final class SystemIdGenerator implements IdGenerator
{
    private readonly Randomizer $random;

    private ?Uuid7 $last = null;

    public function __construct(private readonly Clock $clock)
    {
        $this->random = new Randomizer(new Secure);
    }

    public function next(): Uuid7
    {
        $this->last = Uuid7::generate(
            Uuid7::unixMillisecondsOf($this->clock->now()),
            $this->random,
            $this->last,
        );

        return $this->last;
    }
}
