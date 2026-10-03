<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A theme file that cannot be used (theme.v1.json): it cannot be read, is not JSON of the theme's
 * form, names a token or part hook the catalogue does not have or a primitive token, or sets a
 * value that is not of its token's type or for one mode only. Every reason is listed, each with
 * where in the file it is, so one run shows all of them.
 */
#[Experimental]
final class InvalidTheme extends RuntimeException
{
    /**
     * @param  non-empty-list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
