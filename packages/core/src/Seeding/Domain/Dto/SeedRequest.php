<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Seeding\Domain\InvalidSeed;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Seeding\Domain\SeedRequestLimits;

/**
 * One seed run: the profile, the seed and how many entries the data set has. The run's chunks are
 * the profile's chunk size each, the last one what is left; chunk n holds the entries from
 * n × chunk size, so the chunks of a smaller run are the first chunks of a larger one but its last.
 */
#[Internal]
final readonly class SeedRequest
{
    /**
     * @throws InvalidSeed for a negative seed, or entries outside 1 to SeedRequestLimits::MAX_ENTRIES
     */
    public function __construct(
        public SeedProfile $profile,
        public int $seed,
        public int $entries,
    ) {
        if ($seed < 0) {
            throw InvalidSeed::seed($seed);
        }

        if ($entries < 1 || $entries > SeedRequestLimits::MAX_ENTRIES) {
            throw InvalidSeed::entries($entries);
        }
    }

    public function chunks(): int
    {
        return intdiv($this->entries + $this->profile->chunkSize - 1, $this->profile->chunkSize);
    }

    /**
     * The index of the chunk's first entry.
     *
     * @throws InvalidSeed for a chunk the run does not have
     */
    public function firstOf(int $chunk): int
    {
        if ($chunk < 0 || $chunk >= $this->chunks()) {
            throw InvalidSeed::chunk($chunk, $this->chunks());
        }

        return $chunk * $this->profile->chunkSize;
    }

    /**
     * How many entries the chunk holds.
     *
     * @throws InvalidSeed for a chunk the run does not have
     */
    public function sizeOf(int $chunk): int
    {
        return min($this->profile->chunkSize, $this->entries - $this->firstOf($chunk));
    }

    /**
     * The unit of work of the chunk (PRD 6.1), from which its idempotency key is derived:
     * "seed:<profile>@<version>:<seed>:<chunk>:<entries of the chunk>".
     */
    public function unitOf(int $chunk): string
    {
        return sprintf('seed:%s:%d:%d:%d', $this->profile->label(), $this->seed, $chunk, $this->sizeOf($chunk));
    }

    /**
     * The key of the run's operation: the profile, the seed and the entries.
     */
    public function operationKey(): string
    {
        return sprintf('%s:%d:%d', $this->profile->label(), $this->seed, $this->entries);
    }
}
