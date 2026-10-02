<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * What one shard of mutation on changed files reports to the verdict: which shard of how many it
 * ran, the sources it mutated, whether its `composer check` passed, and the count of each source
 * its steps judged (MutationTally). Written by `composer check -- --pr --shard=<i>/<n>
 * --mutation-report=<file>`, as MutationShardReportJson.
 */
final readonly class MutationShardReport
{
    /**
     * @param  list<string>  $paths  the shard's sources, sorted
     * @param  list<ClassTally>  $classes  sorted by path
     */
    public function __construct(
        public int $index,
        public int $count,
        public array $paths,
        public bool $passed,
        public array $classes,
    ) {
        if ($count < 1 || $index < 1 || $index > $count) {
            throw new InvalidArgumentException("Shard {$index} of {$count} is not a shard.");
        }

        $sorted = $paths;
        sort($sorted, SORT_STRING);

        if ($sorted !== $paths || count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException("The sources of the report of shard {$index} are not sorted, each once.");
        }

        $classPaths = array_map(static fn (ClassTally $class): string => $class->path, $classes);
        $sortedClasses = $classPaths;
        sort($sortedClasses, SORT_STRING);

        if ($sortedClasses !== $classPaths || count(array_unique($classPaths)) !== count($classPaths) || array_diff($classPaths, $paths) !== []) {
            throw new InvalidArgumentException("The classes of the report of shard {$index} are not its sources, sorted, each once.");
        }
    }

    public function shard(MutationShard $shard): bool
    {
        return $this->index === $shard->index && $this->count === $shard->count && $this->paths === $shard->paths();
    }

    public function tally(string $path): ?ClassTally
    {
        foreach ($this->classes as $class) {
            if ($class->path === $path) {
                return $class;
            }
        }

        return null;
    }
}
