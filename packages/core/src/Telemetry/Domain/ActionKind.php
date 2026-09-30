<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Telemetry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Which pipeline ran an action's call: the command pipeline for a write, the query pipeline for a
 * read. It is the `cms.action.kind` of the call's span and metrics.
 */
#[Internal]
enum ActionKind: string
{
    case Command = 'command';

    case Query = 'query';
}
