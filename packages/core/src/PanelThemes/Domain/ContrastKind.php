<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a contrast pair of the token catalogue draws, and the lowest ratio WCAG 2.2 AA allows it:
 * text on a background, 4.5:1 (1.4.3), or a user interface part or the focus ring, 3:1 (1.4.11).
 */
#[Experimental]
enum ContrastKind: string
{
    case Text = 'text';
    case Ui = 'ui';

    public function minimum(): float
    {
        return match ($this) {
            self::Text => 4.5,
            self::Ui => 3.0,
        };
    }
}
