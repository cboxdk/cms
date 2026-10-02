<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\Result;
use Override;

/**
 * The query action the query contract kernel binds to every query of the registry. The query it
 * gets is the real query, read from the surface's document by the query's generated codec; it
 * costs one and answers with the result the scenario gives (QueryContractKernel::answer()), and
 * the kernel keeps every query it handled.
 *
 * @implements QueryAction<Query, Result>
 */
final readonly class ContractQueryAction implements QueryAction
{
    public function __construct(private QueryContractKernel $kernel) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    #[Override]
    public function handle(Query $query): Result
    {
        return $this->kernel->handle($query);
    }
}
