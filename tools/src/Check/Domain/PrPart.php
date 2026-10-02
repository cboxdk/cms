<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * Which part of the PR profile one `composer check -- --pr` runs. CI runs the PR profile as
 * parallel jobs (.github/workflows/ci.yml): one for the gates, without mutation on changed files,
 * and one per shard of mutation on changed files (MutationShards), which runs only that shard's
 * steps; a last job judges them together (MutationVerdict). All is the whole profile in one run,
 * with every changed source in one shard, as a developer runs it.
 */
final readonly class PrPart
{
    private function __construct(
        public ?int $shard,
        public ?int $shards,
        public bool $gatesOnly,
    ) {}

    public static function all(): self
    {
        return new self(null, null, false);
    }

    public static function gates(): self
    {
        return new self(null, null, true);
    }

    public static function shard(int $shard, int $shards): self
    {
        if ($shards < 1 || $shard < 1 || $shard > $shards) {
            throw new InvalidArgumentException("Shard {$shard} of {$shards} is not a shard: it is numbered from 1 to the number of shards.");
        }

        return new self($shard, $shards, false);
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
        return ! $this->gatesOnly;
    }

    public function description(): string
    {
        return match (true) {
            $this->shard !== null => "mutation on changed files, shard {$this->shard} of {$this->shards}",
            $this->gatesOnly => 'the gates, with mutation on changed files run in its shards',
            default => 'every gate',
        };
    }
}
