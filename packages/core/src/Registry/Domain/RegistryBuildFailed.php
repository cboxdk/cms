<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use RuntimeException;

/**
 * cms:build found problems in the scan roots and wrote nothing. Every problem is listed with its
 * code, so one run shows all of them.
 */
#[Experimental]
final class RegistryBuildFailed extends RuntimeException
{
    /**
     * @param  non-empty-list<BuildProblem>  $problems  sorted by code, then message
     */
    private function __construct(public readonly array $problems)
    {
        parent::__construct(sprintf(
            "The registry was not built, and the cache was left as it was. %d %s:\n%s",
            count($problems),
            count($problems) === 1 ? 'problem' : 'problems',
            implode("\n", array_map(static fn (BuildProblem $problem): string => $problem->describe(), $problems)),
        ));
    }

    /**
     * @param  non-empty-list<BuildProblem>  $problems
     */
    public static function with(array $problems): self
    {
        usort($problems, static fn (BuildProblem $a, BuildProblem $b): int => [$a->code->value, $a->message] <=> [$b->code->value, $b->message]);

        return new self($problems);
    }

    /**
     * @return list<BuildErrorCode>
     */
    public function codes(): array
    {
        return array_map(static fn (BuildProblem $problem): BuildErrorCode => $problem->code, $this->problems);
    }
}
