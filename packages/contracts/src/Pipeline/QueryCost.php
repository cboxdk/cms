<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * What a read costs, in the units of the query pipeline's budgets (PRD 6.2, 8.8): a whole number
 * from 0. A query action states the cost of a query from the query alone, before anything is read,
 * from what makes a read expensive: the rows it may return, how deep it reads and how many
 * relations it expands (PRD 8.8). The pipeline rejects a read whose cost is above the budget of its
 * principal with query_over_budget, before the action runs.
 */
#[Experimental]
final readonly class QueryCost
{
    /**
     * @throws InvalidArgumentException when $units is below 0
     */
    public function __construct(public int $units)
    {
        if ($units < 0) {
            throw new InvalidArgumentException(sprintf('A query cost is a whole number from 0; it is %d.', $units));
        }
    }

    /**
     * Whether this cost is above the budget, so a read of it is refused.
     */
    public function exceeds(self $budget): bool
    {
        return $this->units > $budget->units;
    }
}
