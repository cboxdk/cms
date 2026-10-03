<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a file of an addon's panel bundle is: an ES module, a stylesheet whose rules sit in the
 * addon's cascade layer, or another asset such as an image the addon's UI shows.
 */
#[Experimental]
enum BundleFileKind: string
{
    case Script = 'script';
    case Style = 'style';
    case Asset = 'asset';
}
