<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * Which part of the PR profile one `composer check -- --pr` runs. CI runs the parts as parallel
 * jobs, so each part has the 15-minute budget of GUARDRAILS 10 to itself, and a verdict job over
 * them fails unless every part reported.
 *
 * Without mutation testing, the run has three parts: the gates (suiteGates, `--only=gates`), which
 * run every gate but the suites of ShardPlan and report those as run in the shards; one shard per
 * shard of the plan (suiteShard, `--shard=<i>/<n>`), which runs only its share of them and reports
 * every other gate as run in the gates job; and the verdict (`composer shards:verdict`), which
 * judges them together. withoutMutation() is the whole profile in one run, as a developer runs it.
 *
 * Mutation testing, the Mutation suite and mutation on changed files, is deferred until after v1
 * (Sylvester, 2 October 2026): it made every merge take far longer, and the architecture will
 * change before v1. So the PR profile runs without it by default (withoutMutation): every gate,
 * with the steps of mutation testing reported as not run. `--mutation` opts in to the parts that
 * run it, as CI's run by hand with the input mutation does (.github/workflows/ci.yml): one for
 * the gates with the Mutation suite but without mutation on changed files, and one per shard of
 * mutation on changed files (MutationShards), which runs only that shard's steps; a last job
 * judges them together (MutationVerdict). Such a run keeps the sharded suites whole in its gates
 * part, so its shards are the shards of mutation on changed files alone. All is the whole profile
 * with mutation testing in one run, with every changed source in one shard.
 */
final readonly class PrPart
{
    private function __construct(
        public ?int $shard,
        public ?int $shards,
        public bool $gatesOnly,
        public bool $mutationTesting,
        public bool $shardedSuites,
    ) {}

    /**
     * The default of `--pr`: every gate in one run, with mutation testing reported as not run.
     */
    public static function withoutMutation(): self
    {
        return new self(null, null, true, false, false);
    }

    /**
     * `--pr --only=gates`: every gate but the suites of ShardPlan, which the shards run.
     */
    public static function suiteGates(): self
    {
        return new self(null, null, true, false, true);
    }

    /**
     * `--pr --shard=<i>/<n>`: only shard i of the n shards of the declared plan of the sharded
     * suites (ShardPlan). The plan is declared, so a count other than the plan's is a usage error.
     */
    public static function suiteShard(int $shard, int $shards): self
    {
        if ($shards !== ShardPlan::SHARDS) {
            throw new InvalidArgumentException("The plan of {$shards} shards is not the plan of ".ShardPlan::SHARDS.' shards that CI runs (ShardPlan).');
        }

        if ($shard < 1 || $shard > $shards) {
            throw new InvalidArgumentException("Shard {$shard} of {$shards} is not a shard: it is numbered from 1 to the number of shards.");
        }

        return new self($shard, $shards, false, false, true);
    }

    /**
     * `--pr --mutation`: every gate and mutation testing in one run.
     */
    public static function all(): self
    {
        return new self(null, null, false, true, false);
    }

    /**
     * `--pr --mutation --only=gates`: every gate and the Mutation suite, with mutation on changed
     * files run in its shards.
     */
    public static function gates(): self
    {
        return new self(null, null, true, true, false);
    }

    /**
     * `--pr --mutation --shard=<i>/<n>`: only shard i of mutation on changed files.
     */
    public static function shard(int $shard, int $shards): self
    {
        if ($shards < 1 || $shard < 1 || $shard > $shards) {
            throw new InvalidArgumentException("Shard {$shard} of {$shards} is not a shard: it is numbered from 1 to the number of shards.");
        }

        return new self($shard, $shards, false, true, false);
    }

    public function isShard(): bool
    {
        return $this->shard !== null;
    }

    /**
     * Whether this part is one shard of the sharded suites (ShardPlan).
     */
    public function isSuiteShard(): bool
    {
        return $this->shardedSuites && $this->shard !== null;
    }

    /**
     * Whether this part is one shard of mutation on changed files.
     */
    public function isMutationShard(): bool
    {
        return $this->mutationTesting && $this->shard !== null;
    }

    /**
     * Whether this part runs the gates other than the sharded work of its run.
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
            $this->isSuiteShard() => sprintf('%s, shard %d of %d', ShardPlan::describe(), (int) $this->shard, (int) $this->shards),
            $this->shardedSuites => 'the gates, with '.ShardPlan::describe().' run in their shards',
            ! $this->mutationTesting => 'every gate, without mutation testing, which is deferred until after v1',
            $this->shard !== null => "mutation on changed files, shard {$this->shard} of {$this->shards}",
            $this->gatesOnly => 'the gates, with mutation on changed files run in its shards',
            default => 'every gate, with mutation testing',
        };
    }
}
