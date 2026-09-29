<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The query pipeline's settings (PRD 6.2, 8.8), from `cbox-cms.queries`: the cost budget of a read
 * as the anonymous principal and as an actor. A read that costs more than its principal's budget
 * is rejected with query_over_budget before it reads anything.
 */
#[Internal]
final readonly class QuerySettings
{
    public function __construct(
        public QueryCost $anonymousBudget,
        public QueryCost $actorBudget,
    ) {}

    public function budgetOf(Principal $principal): QueryCost
    {
        return $principal instanceof ActorPrincipal ? $this->actorBudget : $this->anonymousBudget;
    }
}
