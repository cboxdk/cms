<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why the panel did not run a contribution's data query (PRD 13.4): no codec is registered for
 * the query (QueryCodecs), or the point's props, written for the contribution, do not give an input
 * the query's codec reads.
 */
#[Experimental]
enum DataRefusal: string
{
    case NoCodec = 'query_without_codec';
    case InputInvalid = 'input_invalid';
}
