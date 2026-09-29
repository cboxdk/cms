<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;

/**
 * The query action of a query, as the query pipeline asks for it (GUARDRAILS 2.1, PRD 13.2): the
 * action cms:build registered for the query's class, with the query's name and version from its
 * #[Query].
 */
#[Internal]
interface QueryActions
{
    /**
     * @throws UnknownQuery when no query action handles the query's class
     */
    public function for(Query $query): QueryBinding;
}
