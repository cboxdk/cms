<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use InvalidArgumentException;

/**
 * Nothing was committed, because aggregates the call read changed before its commit (invariants
 * 11 and 37): each with the version read and the version now, sorted by aggregate key.
 */
#[Internal]
final readonly class VersionConflict implements CommitOutcome
{
    /** @var list<StaleRead> */
    public array $stale;

    public function __construct(StaleRead ...$stale)
    {
        if ($stale === []) {
            throw new InvalidArgumentException('A version conflict names at least one stale read.');
        }

        usort($stale, static fn (StaleRead $one, StaleRead $other): int => strcmp($one->aggregate->aggregateKey(), $other->aggregate->aggregateKey()));
        $this->stale = $stale;
    }
}
