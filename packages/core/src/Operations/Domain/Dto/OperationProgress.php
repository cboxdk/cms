<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationState;

/**
 * An operation as laravel-operations records it: its state, the chunks that completed and the
 * chunks still to run, both in plan order.
 */
#[Experimental]
final readonly class OperationProgress
{
    /**
     * @param  list<ChunkName>  $completed
     * @param  list<ChunkName>  $remaining
     */
    public function __construct(
        public OperationId $id,
        public OperationKind $kind,
        public OperationKey $key,
        public OperationState $state,
        public array $completed,
        public array $remaining,
    ) {}

    /**
     * The names of the completed chunks, in plan order.
     *
     * @return list<string>
     */
    public function completedNames(): array
    {
        return array_map(static fn (ChunkName $chunk): string => $chunk->value, $this->completed);
    }

    /**
     * The names of the chunks still to run, in plan order.
     *
     * @return list<string>
     */
    public function remainingNames(): array
    {
        return array_map(static fn (ChunkName $chunk): string => $chunk->value, $this->remaining);
    }
}
