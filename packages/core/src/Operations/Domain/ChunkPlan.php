<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The chunks of an operation in the order they run, planned once when the operation starts. Every
 * chunk has its own name. A plan may be empty: the operation then completes without running a chunk.
 */
#[Experimental]
final readonly class ChunkPlan
{
    /** @var list<ChunkName> */
    public array $chunks;

    /**
     * @throws InvalidOperation when two chunks have the same name
     */
    public function __construct(ChunkName ...$chunks)
    {
        $seen = [];

        foreach ($chunks as $chunk) {
            if (isset($seen[$chunk->value])) {
                throw InvalidOperation::duplicateChunk($chunk);
            }

            $seen[$chunk->value] = true;
        }

        $this->chunks = array_values($chunks);
    }

    /**
     * The chunk names, in order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ChunkName $chunk): string => $chunk->value, $this->chunks);
    }
}
