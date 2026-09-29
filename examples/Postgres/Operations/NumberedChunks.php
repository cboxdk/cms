<?php

declare(strict_types=1);

namespace Examples\Postgres\Operations;

use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Override;
use RuntimeException;

/**
 * A chunked action that works through numbered chunks. A real one plans its chunks from the data,
 * such as one chunk per thousand rows, and each chunk commits its own changeset with an idempotency
 * key derived from the operation key and the chunk name, so running a chunk twice changes nothing.
 * This one records the chunks it ran, and its first run of chunk-2 fails.
 */
final class NumberedChunks implements ChunkedAction
{
    /** @var list<string> */
    public array $ran = [];

    private bool $failed = false;

    public function __construct(private readonly int $count) {}

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind('example.numbered');
    }

    #[Override]
    public function chunks(): ChunkPlan
    {
        return new ChunkPlan(...array_map(
            static fn (int $number): ChunkName => new ChunkName('chunk-'.$number),
            range(1, $this->count),
        ));
    }

    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        if ($chunk->value === 'chunk-2' && ! $this->failed) {
            $this->failed = true;

            throw new RuntimeException('The database went away for a moment.');
        }

        $this->ran[] = $chunk->value;
    }
}
