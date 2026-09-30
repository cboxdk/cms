<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The direction of one key of a type table query's order (PRD 8.8). Null sorts as the greatest
 * value, as Postgres sorts it by default: last when ascending, first when descending.
 */
#[Experimental]
enum SortDirection: string
{
    case Ascending = 'asc';

    case Descending = 'desc';
}
