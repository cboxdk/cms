<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How the edge treats the objects a purge names (PRD 8.12 point 3).
 *
 * Soft marks them stale: the edge may serve them with stale-while-revalidate while it fetches
 * them again, the purge for a changed article. Hard removes them: the edge never serves them
 * stale, the purge for a removal and for a hard correction.
 */
#[Experimental]
enum PurgeMode: string
{
    case Soft = 'soft';
    case Hard = 'hard';
}
