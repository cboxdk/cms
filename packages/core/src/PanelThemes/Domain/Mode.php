<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The colour mode a token's value is for.
 */
#[Experimental]
enum Mode: string
{
    case Light = 'light';
    case Dark = 'dark';
}
