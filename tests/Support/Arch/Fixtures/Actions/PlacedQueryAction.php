<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Actions;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\Result;
use Override;

/**
 * A query action in an Actions namespace, where ActionPlacement accepts it.
 *
 * @implements QueryAction<Query, Result>
 */
final readonly class PlacedQueryAction implements QueryAction
{
    #[Override]
    public function handle(Query $query): Result
    {
        return new readonly class implements Result {};
    }
}
