<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use InvalidArgumentException;

/**
 * Lists the grants that have not ended on the nodes the actor's regions reach (PRD 5.10), version 1
 * of grant.list: a page of at most $limit grants in the order of their ids, after the grant $after,
 * or from the first grant when it is null. It needs a role whose permissions name grant.list.
 */
#[QueryName('grant.list', version: 1)]
#[Experimental]
final readonly class ListGrants implements Query
{
    /**
     * @throws InvalidArgumentException when the limit is outside ListLimit
     */
    public function __construct(
        public ?GrantId $after = null,
        public int $limit = ListLimit::DEFAULT,
    ) {
        ListLimit::checked($limit);
    }
}
