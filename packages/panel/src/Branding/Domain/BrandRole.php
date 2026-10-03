<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a brand file is for, which names the file the panel serves it as.
 */
#[Internal]
enum BrandRole: string
{
    case LogoLight = 'logo-light';
    case LogoDark = 'logo-dark';
    case LoginLight = 'login-light';
    case LoginDark = 'login-dark';
    case Favicon = 'favicon';
}
