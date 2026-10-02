<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * How mutation on changed files is split into shards: the base of the change, or why it could not
 * be found, and the shards, numbered from 1, with every changed source in exactly one of them. CI
 * runs a job per shard (.github/workflows/ci.yml), bin/ci runs them one after the other in the
 * containerized run, and MutationVerdict holds the shards' reports to the plan.
 */
final readonly class MutationPlan
{
    /**
     * @param  list<MutationShard>  $shards
     */
    public function __construct(
        public ?string $base,
        public ?string $failure,
        public array $shards,
    ) {
        if (($base === null) === ($failure === null)) {
            throw new InvalidArgumentException('A plan has a base of the change or the reason it has none.');
        }

        if ($shards === []) {
            throw new InvalidArgumentException('A plan has at least one shard.');
        }

        $paths = [];

        foreach ($shards as $position => $shard) {
            if ($shard->index !== $position + 1 || $shard->count !== count($shards)) {
                throw new InvalidArgumentException('The shards of a plan are numbered 1 to their count, in order.');
            }

            foreach ($shard->paths() as $path) {
                if (isset($paths[$path])) {
                    throw new InvalidArgumentException("{$path} is in two shards of the plan.");
                }

                $paths[$path] = true;
            }
        }

        if ($failure !== null && $paths !== []) {
            throw new InvalidArgumentException('A plan without a base has no sources.');
        }
    }

    public function count(): int
    {
        return count($this->shards);
    }

    public function shard(int $index): MutationShard
    {
        return $this->shards[$index - 1] ?? throw new InvalidArgumentException("The plan has {$this->count()} shards, not a shard {$index}.");
    }

    /**
     * What one shard mutates, as a scope of its own: the shard's sources since the plan's base, or
     * the plan's failure.
     */
    public function scope(int $index): MutationScope
    {
        $shard = $this->shard($index);

        if ($this->failure !== null) {
            return MutationScope::unresolved($this->failure);
        }

        return MutationScope::changed(sprintf('%s (%s)', $this->base, $shard->label()), $shard->sources);
    }

    /**
     * Every changed source of the plan, sorted by path.
     *
     * @return list<ChangedSource>
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->shards as $shard) {
            array_push($sources, ...$shard->sources);
        }

        usort($sources, static fn (ChangedSource $a, ChangedSource $b): int => strcmp($a->path, $b->path));

        return $sources;
    }
}
