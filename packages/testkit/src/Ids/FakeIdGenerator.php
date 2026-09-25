<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A deterministic id generator for tests (GUARDRAILS 2.3).
 *
 * It makes ids the way the real generator does, with Uuid7::generate(), but the random bits come
 * from a seeded Xoshiro256** engine instead of the system's secure source. Two fakes with the same
 * seed and the same clock movements give the same ids. The clock defaults to a new FakeClock, so a
 * test that moves no clock gets ids in one millisecond with an increasing counter.
 */
#[Experimental]
final class FakeIdGenerator implements IdGenerator
{
    public const int DEFAULT_SEED = 0;

    private readonly Randomizer $random;

    private ?Uuid7 $last = null;

    public function __construct(
        public readonly int $seed = self::DEFAULT_SEED,
        private readonly Clock $clock = new FakeClock,
    ) {
        $this->random = new Randomizer(new Xoshiro256StarStar($seed));
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
