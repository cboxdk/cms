<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Which keys of a replaceable point an addon may replace: only the keys it owns, its own field
 * types, commands or value classes (Own), or any key (Any). Every point that lets any addon
 * replace any key is a recorded decision.
 */
#[Experimental]
enum Ownership: string
{
    case Own = 'own';
    case Any = 'any';
}
