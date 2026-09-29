<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One read use case (GUARDRAILS 2.1, PRD 6.2): a final readonly class the query pipeline calls
 * after it has set the actor's context, authorized the read and checked its budget. The surfaces
 * the action is exposed on are declared with #[Action].
 *
 * - cost() states what the query costs, from the query alone and before anything is read, so the
 *   pipeline can refuse a read above the principal's budget before it reaches the database.
 * - handle() reads and returns the typed Result; it never writes. A result that carries the fields
 *   of entries implements ReadsContent, so the pipeline can strip the fields above the principal's
 *   classification access and attach the content keys.
 *
 * @template TQuery of Query
 * @template TResult of Result
 */
#[Experimental]
interface QueryAction
{
    /**
     * @param  TQuery  $query
     */
    public function cost(Query $query): QueryCost;

    /**
     * @param  TQuery  $query
     * @return TResult
     */
    public function handle(Query $query): Result;
}
