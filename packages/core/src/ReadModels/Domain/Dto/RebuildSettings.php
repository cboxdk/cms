<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use InvalidArgumentException;

/**
 * A rebuild's settings, from cbox-cms.rebuild (PRD 4.1, invariant 22):
 *
 * - serviceActor: the service actor a rebuild runs as, or null when none is configured.
 * - chunkSize: the most entries one chunk rebuilds in its transaction, 1 to MAX_CHUNK_SIZE.
 */
#[Experimental]
final readonly class RebuildSettings
{
    public const int MAX_CHUNK_SIZE = 1_000;

    /**
     * @throws InvalidArgumentException when the chunk size is outside 1 to MAX_CHUNK_SIZE
     */
    public function __construct(
        public ?ActorId $serviceActor,
        public int $chunkSize = 100,
    ) {
        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf('A chunk of a rebuild holds 1 to %d entries, got %d.', self::MAX_CHUNK_SIZE, $chunkSize));
        }
    }
}
