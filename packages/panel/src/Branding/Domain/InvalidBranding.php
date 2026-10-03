<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;

/**
 * cbox-cms.panel.branding cannot be used: a key of another form, a name longer than 60
 * characters, a logo without its alternative text, or a file that is not a readable SVG or PNG
 * inside the application. Every reason is listed. The panel shows Cbox CMS without branding
 * meanwhile, and cms:doctor's panel.branding says why.
 */
#[Internal]
final class InvalidBranding extends RuntimeException
{
    /**
     * @param  non-empty-list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
