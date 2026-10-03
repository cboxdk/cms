<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The tone a contribution is shown in: neutral, information, a warning or danger. The host maps it
 * to the kit's tones; a tone never changes what a contribution may do.
 */
#[Experimental]
enum Tone: string
{
    case Neutral = 'neutral';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
}
