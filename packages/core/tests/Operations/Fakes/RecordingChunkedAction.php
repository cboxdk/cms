<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Operations\Fakes;

use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Override;
use RuntimeException;

/**
 * A test-only chunked action: it plans the chunks it is given, records every chunk it runs and how
 * often its plan was asked for, and throws once at the chunk named in failOnceAt, as a chunk whose
 * database or network fails for a moment would.
 */
final class RecordingChunkedAction implements ChunkedAction
{
    /** @var list<string> */
    public array $ran = [];

    public int $plans = 0;

    /**
     * @param  list<string>  $chunks
     */
    public function __construct(
        private readonly string $kind,
        private array $chunks,
        private ?string $failOnceAt = null,
    ) {}

    /**
     * The chunks the next plan holds, as data that changed after an operation started would give.
     *
     * @param  list<string>  $chunks
     */
    public function replan(array $chunks): void
    {
        $this->chunks = $chunks;
    }

    public function failOnceAt(string $chunk): void
    {
        $this->failOnceAt = $chunk;
    }

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind($this->kind);
    }

    #[Override]
    public function chunks(): ChunkPlan
    {
        $this->plans++;

        return new ChunkPlan(...array_map(static fn (string $chunk): ChunkName => new ChunkName($chunk), $this->chunks));
    }

    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        if ($chunk->value === $this->failOnceAt) {
            $this->failOnceAt = null;

            throw new RuntimeException("The chunk {$chunk->value} failed.");
        }

        $this->ran[] = $chunk->value;
    }
}
