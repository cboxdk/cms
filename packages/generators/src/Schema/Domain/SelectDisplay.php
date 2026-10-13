<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a form shows the choices of a `select` field, the `ui.display` of its blueprint (gap G2):
 * radio buttons, a row of buttons or a dropdown.
 */
#[Internal]
enum SelectDisplay: string
{
    case Radio = 'radio';

    case Buttons = 'buttons';

    case Dropdown = 'dropdown';
}
