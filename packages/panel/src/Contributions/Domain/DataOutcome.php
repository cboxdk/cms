<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the data query of a contribution ended (PRD 13.4): answered, with the result the
 * contribution gets; rejected by the query pipeline, such as unauthorized or query_over_budget;
 * refused before it ran, because no codec reads the query or the point's props cannot give its
 * input; or failed with an exception. Every outcome but answered leaves the contribution's data
 * absent, and the page still answers.
 */
#[Experimental]
enum DataOutcome: string
{
    case Answered = 'answered';
    case Rejected = 'rejected';
    case Refused = 'refused';
    case Failed = 'failed';
}
