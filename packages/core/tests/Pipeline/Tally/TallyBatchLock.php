<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\BatchVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Override;

/**
 * The test-only version lock of tallies that can lock a run at once: it notes each call, a run of
 * one as a single lock(), and locks through TallyVersionLock.
 */
final class TallyBatchLock implements BatchVersionLock
{
    /** @var list<string> each call, as "<tally ids> <strength>" */
    public array $calls = [];

    private readonly TallyVersionLock $lock;

    public function __construct()
    {
        $this->lock = new TallyVersionLock;
    }

    #[Override]
    public function kind(): string
    {
        return TallyTable::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        $this->calls[] = $aggregate->aggregateKey().' '.$strength->value;

        return $this->lock->lock($aggregate, $strength);
    }

    #[Override]
    public function lockAll(array $aggregates, LockStrength $strength): array
    {
        $this->calls[] = implode(',', array_map(static fn (AggregateRef $aggregate): string => $aggregate->aggregateKey(), $aggregates)).' '.$strength->value;
        $versions = [];

        foreach ($aggregates as $aggregate) {
            $versions[$aggregate->aggregateKey()] = $this->lock->lock($aggregate, $strength);
        }

        return $versions;
    }
}
