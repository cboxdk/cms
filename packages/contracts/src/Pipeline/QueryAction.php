<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One read use case (GUARDRAILS 2.1, PRD 6.2): a final readonly class the query pipeline calls
 * after it has set the actor's context, authorized the read and checked its budget. handle()
 * reads and returns the typed Result; it never writes. The surfaces the action is exposed on are
 * declared with #[Action].
 *
 * @template TQuery of Query
 * @template TResult of Result
 */
#[Experimental]
interface QueryAction
{
    /**
     * @param  TQuery  $query
     * @return TResult
     */
    public function handle(Query $query): Result;
}
