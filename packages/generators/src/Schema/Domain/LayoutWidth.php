<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The width presets a placement in a blueprint's `layout` takes (gap G2): the whole row of a
 * section, or a half, a third or two thirds of it. The blueprint schema takes any handle-like
 * word for `width`, and BlueprintRules refuses one that is not a preset with
 * generate_layout_width_unknown, so the message names the presets.
 */
#[Internal]
enum LayoutWidth: string
{
    case Full = 'full';

    case Half = 'half';

    case Third = 'third';

    case TwoThirds = 'two_thirds';

    /** The width of a placement that names none. */
    public const self DEFAULT = self::Full;

    /**
     * The presets as a placement writes them, for a problem that names them.
     */
    public static function names(): string
    {
        return implode(', ', array_map(static fn (self $width): string => $width->value, self::cases()));
    }
}
