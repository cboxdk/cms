<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * One shard of mutation on changed files: shard $index of $count, and the changed sources it
 * mutates, sorted by path. A shard of a change without sources mutates nothing and passes.
 */
final readonly class MutationShard
{
    /**
     * @param  list<ChangedSource>  $sources
     */
    public function __construct(
        public int $index,
        public int $count,
        public array $sources,
    ) {
        if ($count < 1 || $index < 1 || $index > $count) {
            throw new InvalidArgumentException("Shard {$index} of {$count} is not a shard.");
        }

        $paths = $this->paths();
        $sorted = $paths;
        sort($sorted, SORT_STRING);

        if ($paths !== $sorted || count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException("The sources of shard {$index} of {$count} are not sorted by path, each once.");
        }
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (ChangedSource $source): string => $source->path, $this->sources);
    }

    public function label(): string
    {
        return "shard {$this->index} of {$this->count}";
    }
}
