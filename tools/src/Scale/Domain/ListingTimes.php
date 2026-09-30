<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Domain;

/**
 * The execution times of one listing over the runs, in milliseconds as EXPLAIN (ANALYZE) reports
 * them, each run the sum of the listing's statements, and whether their median is within a budget.
 */
final readonly class ListingTimes
{
    /**
     * @param  non-empty-list<float>  $milliseconds  one per run
     */
    public function __construct(
        public string $listing,
        public array $milliseconds,
    ) {}

    public function median(): float
    {
        $sorted = $this->milliseconds;
        sort($sorted);
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    public function within(float $budget): bool
    {
        return $this->median() <= $budget;
    }

    /**
     * One line: the listing, its median, the budget and the verdict.
     */
    public function line(float $budget): string
    {
        return sprintf(
            '%s: median %.3f ms over %d runs (min %.3f, max %.3f), budget %.1f ms: %s',
            $this->listing,
            $this->median(),
            count($this->milliseconds),
            min($this->milliseconds),
            max($this->milliseconds),
            $budget,
            $this->within($budget) ? 'pass' : 'fail',
        );
    }
}
