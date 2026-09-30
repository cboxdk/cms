<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;

/**
 * The chunks one run of a rebuild ran, in the order they ran, for its report. It lives as long as
 * the run: a run that resumes an operation reports the chunks it ran itself.
 */
#[Internal]
final class RebuildTally
{
    /** @var list<ChunkResult> */
    private array $results = [];

    public function add(ChunkResult $result): void
    {
        $this->results[] = $result;
    }

    /**
     * @return list<ChunkResult>
     */
    public function results(): array
    {
        return $this->results;
    }
}
