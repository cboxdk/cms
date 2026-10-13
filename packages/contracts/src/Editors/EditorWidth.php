<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The width presets a field takes in a section of a form (PRD 14.1): the whole row, or a half,
 * a third or two thirds of it, as the blueprint's layout places the field.
 */
#[Experimental]
enum EditorWidth: string
{
    case Full = 'full';

    case Half = 'half';

    case Third = 'third';

    case TwoThirds = 'two_thirds';
}
