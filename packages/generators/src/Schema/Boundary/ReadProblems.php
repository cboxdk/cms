<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;

/**
 * The problems found while one blueprint document is read, in the order they were found. The
 * values of every object in the document add to the same list.
 */
#[Internal]
final class ReadProblems
{
    /** @var list<GenerationProblem> */
    private array $problems = [];

    public function add(GenerationProblem $problem): void
    {
        $this->problems[] = $problem;
    }

    /**
     * @return list<GenerationProblem>
     */
    public function all(): array
    {
        return $this->problems;
    }
}
