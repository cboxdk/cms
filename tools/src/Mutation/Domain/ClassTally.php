<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * The mutations of one changed source over every run that mutated it, and how many a test caught:
 * the line a shard's report keeps per class, and what MutationVerdict judges at the minimum score.
 */
final readonly class ClassTally
{
    public function __construct(
        public string $path,
        public string $name,
        public MutationCount $count,
    ) {
        if ($path === '' || str_contains($path, "\n") || $name === '' || str_contains($name, "\n")) {
            throw new InvalidArgumentException('A class tally needs a one-line path and name.');
        }
    }

    /**
     * Whether the class reaches the minimum score over all its mutations. A class without
     * mutations, such as an interface, has nothing to fall below it.
     */
    public function reaches(int $minScore): bool
    {
        return $this->count->mutations === 0 || $this->count->score() >= $minScore;
    }

    public function describe(): string
    {
        $equivalent = $this->count->equivalent === 0 ? '' : sprintf(', %d equivalent left out', $this->count->equivalent);

        return $this->count->mutations === 0
            ? "{$this->name}: no mutations{$equivalent}"
            : sprintf('%s: %s%%, %d of %d mutations caught%s', $this->name, number_format($this->count->score(), 2), $this->count->caught, $this->count->mutations, $equivalent);
    }
}
