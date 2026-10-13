<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a resolution ends in a redirect (PRD 5.8, 5.9, 8.10): the page lives at another URL, reached
 * in one hop. The outcome is one for every cause, so a later cause, such as a slug that changed or
 * an entry that was merged, is a case here and nothing else.
 *
 * - OwnPlacement: the site has a placement of its own of the entry a mount on the site shows, which
 *   suppresses the mounted one, so the mount URL redirects to the site's own (PRD 5.8).
 */
#[Experimental]
enum RedirectReason: string
{
    case OwnPlacement = 'own_placement';
}
