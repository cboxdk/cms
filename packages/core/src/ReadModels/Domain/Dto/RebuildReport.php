<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;

/**
 * What a rebuild did: the type, the service actor it ran as, the operation with its completed
 * chunks, and the chunks this run ran, in order, each with its counts and time. A run that found
 * the operation completed ran no chunk.
 */
#[Experimental]
final readonly class RebuildReport
{
    /**
     * @param  list<ChunkResult>  $chunks
     */
    public function __construct(
        public TypeName $type,
        public ActorId $actor,
        public OperationProgress $operation,
        public array $chunks,
    ) {}

    /**
     * The entries this run rebuilt.
     */
    public function entries(): int
    {
        return array_sum(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $this->chunks));
    }

    /**
     * The longest transaction of this run's chunks, in milliseconds; 0 when it ran none.
     */
    public function longestMilliseconds(): int
    {
        return array_reduce($this->chunks, static fn (int $longest, ChunkResult $chunk): int => max($longest, $chunk->milliseconds), 0);
    }
}
