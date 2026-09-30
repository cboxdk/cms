<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The unit of a histogram's values, as its UCUM code, the form OpenTelemetry uses.
 */
#[Experimental]
enum MetricUnit: string
{
    case Milliseconds = 'ms';

    case Bytes = 'By';

    /** A count without a unit. */
    case One = '1';
}
