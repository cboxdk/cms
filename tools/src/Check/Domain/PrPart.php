<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * Which part of the PR profile one `composer check -- --pr` runs.
 *
 * Mutation testing, the Mutation suite and mutation on changed files, is deferred until after v1
 * (Sylvester, 2 October 2026): it made every merge take far longer, and the architecture will
 * change before v1. So the PR profile runs without it by default (withoutMutation): every gate,
 * with the steps of mutation testing reported as not run. `--mutation` opts in to the parts that
 * run it, as CI's run by hand with the input mutation does (.github/workflows/ci.yml): one for
 * the gates with the Mutation suite but without mutation on changed files, and one per shard of
 * mutation on changed files (MutationShards), which runs only that shard's steps; a last job
 * judges them together (MutationVerdict). All is the whole profile with mutation testing in one
 * run, with every changed source in one shard.
 */
final readonly class PrPart
{
    private function __construct(
        public ?int $shard,
        public ?int $shards,
        public bool $gatesOnly,
        public bool $mutationTesting,
    ) {}

    /**
     * The default of `--pr`: every gate, with mutation testing reported as not run.
     */
    public static function withoutMutation(): self
    {
        return new self(null, null, true, false);
    }

    /**
     * `--pr --mutation`: every gate and mutation testing in one run.
     */
    public static function all(): self
    {
        return new self(null, null, false, true);
    }

    /**
     * `--pr --mutation --only=gates`: every gate and the Mutation suite, with mutation on changed
     * files run in its shards.
     */
    public static function gates(): self
    {
        return new self(null, null, true, true);
    }

    /**
     * `--pr --mutation --shard=<i>/<n>`: only shard i of mutation on changed files.
     */
    public static function shard(int $shard, int $shards): self
    {
        if ($shards < 1 || $shard < 1 || $shard > $shards) {
            throw new InvalidArgumentException("Shard {$shard} of {$shards} is not a shard: it is numbered from 1 to the number of shards.");
        }

        return new self($shard, $shards, false, true);
    }

    public function isShard(): bool
    {
        return $this->shard !== null;
    }

    /**
     * Whether this part runs the gates other than mutation on changed files.
     */
    public function runsGates(): bool
    {
        return $this->shard === null;
    }

    /**
     * Whether this part runs mutation on changed files.
     */
    public function runsMutation(): bool
    {
        return $this->mutationTesting && ! $this->gatesOnly;
    }

    /**
     * Whether this part runs the Mutation suite, the tests of mutation on changed files.
     */
    public function runsMutationSuite(): bool
    {
        return $this->mutationTesting && $this->runsGates();
    }

    public function description(): string
    {
        return match (true) {
            ! $this->mutationTesting => 'every gate, without mutation testing, which is deferred until after v1',
            $this->shard !== null => "mutation on changed files, shard {$this->shard} of {$this->shards}",
            $this->gatesOnly => 'the gates, with mutation on changed files run in its shards',
            default => 'every gate, with mutation testing',
        };
    }
}
