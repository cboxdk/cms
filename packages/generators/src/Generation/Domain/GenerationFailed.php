<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use RuntimeException;
use Throwable;

/**
 * cms:generate stopped. Every problem found in the same step is listed with its code, so one run
 * shows all of them. Nothing is written unless generation succeeded.
 */
#[Internal]
final class GenerationFailed extends RuntimeException
{
    /**
     * @param  non-empty-list<GenerationProblem>  $problems  sorted by code, then message
     */
    private function __construct(public readonly array $problems, ?Throwable $previous)
    {
        parent::__construct(sprintf(
            "cms:generate stopped. %d %s:\n%s",
            count($problems),
            count($problems) === 1 ? 'problem' : 'problems',
            implode("\n", array_map(static fn (GenerationProblem $problem): string => $problem->describe(), $problems)),
        ), 0, $previous);
    }

    /**
     * @param  non-empty-list<GenerationProblem>  $problems
     */
    public static function with(array $problems, ?Throwable $previous = null): self
    {
        usort($problems, static fn (GenerationProblem $a, GenerationProblem $b): int => [$a->code->value, $a->message] <=> [$b->code->value, $b->message]);

        return new self($problems, $previous);
    }

    public static function because(GenerateErrorCode $code, string $message, ?Throwable $previous = null): self
    {
        return self::with([new GenerationProblem($code, $message)], $previous);
    }

    /**
     * @return list<GenerateErrorCode>
     */
    public function codes(): array
    {
        return array_map(static fn (GenerationProblem $problem): GenerateErrorCode => $problem->code, $this->problems);
    }
}
