<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How much an issue of a form check weighs (PRD 13.4): information, a warning, one the viewer
 * acknowledges with an explicit tick before submitting, or an error. An error blocks the client
 * submit only when the check mirrors a server hook of the same addon on the same command (the
 * mirror rule), so the rule holds over every surface.
 */
#[Experimental]
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Acknowledge = 'acknowledge';
    case Error = 'error';
}
