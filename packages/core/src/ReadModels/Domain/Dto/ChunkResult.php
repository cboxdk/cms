<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use InvalidArgumentException;

/**
 * One chunk of a rebuild that committed: its name, the entries and variant heads it rebuilt, and
 * how long its transaction took, from its first statement to its commit, in milliseconds of real
 * time.
 */
#[Experimental]
final readonly class ChunkResult
{
    /**
     * @throws InvalidArgumentException when a count or the time is negative
     */
    public function __construct(
        public ChunkName $chunk,
        public int $entries,
        public int $variants,
        public int $milliseconds,
    ) {
        if ($entries < 0 || $variants < 0 || $milliseconds < 0) {
            throw new InvalidArgumentException(sprintf('A chunk of a rebuild has no negative count or time, got %d entries, %d variants and %d ms.', $entries, $variants, $milliseconds));
        }
    }
}
