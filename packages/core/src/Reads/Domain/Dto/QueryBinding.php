<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;

/**
 * A query's name and version, from its #[Query], and the query action that handles it.
 */
#[Internal]
final readonly class QueryBinding
{
    /**
     * @param  QueryAction<Query, Result>  $action  an action for the query's class, as the registry matched it
     *
     * @throws UnknownQuery when the version is below 1
     */
    public function __construct(
        public CommandName $query,
        public int $version,
        public QueryAction $action,
    ) {
        if ($version < 1) {
            throw UnknownQuery::version($query->value, $version);
        }
    }
}
