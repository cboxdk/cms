<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A relation a link to a URL declares between the page and the URL's target, as the HTML
 * attribute `rel` writes it: the link is not endorsed (`nofollow`), the target gets no handle to
 * the page (`noopener`) and no referrer (`noreferrer`), the link is paid (`sponsored`) or written
 * by a user (`ugc`).
 */
#[Experimental]
enum LinkRel: string
{
    case Nofollow = 'nofollow';
    case Noopener = 'noopener';
    case Noreferrer = 'noreferrer';
    case Sponsored = 'sponsored';
    case Ugc = 'ugc';
}
