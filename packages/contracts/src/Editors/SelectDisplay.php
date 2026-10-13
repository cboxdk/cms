<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a form shows the choices of a select field (PRD 14.1): radio buttons, a row of buttons or a
 * dropdown, as the blueprint's `ui.display` asks. A select without it leaves the input to the
 * form.
 */
#[Experimental]
enum SelectDisplay: string
{
    case Radio = 'radio';

    case Buttons = 'buttons';

    case Dropdown = 'dropdown';
}
