<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the work a span describes ended: ok, or with an error. For a pipeline's span, a call that
 * was answered, committed or run dry is ok, and a call that was rejected or threw is an error.
 */
#[Experimental]
enum SpanStatus: string
{
    case Ok = 'ok';

    case Error = 'error';
}
