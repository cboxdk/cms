<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How long a caller waits before the command returns its receipt (PRD 8.4). It is set in the
 * envelope (PRD 6.1), and Commit is the default.
 */
#[Experimental]
enum WaitLevel: string
{
    /** The transaction is committed. */
    case Commit = 'commit';

    /** Server fragments are invalidated. */
    case Origin = 'origin';

    /** The CDN's API accepted the purge. That is receipt, not confirmed effect. */
    case Edge = 'edge';

    /** For removals: a probe through the edge after a double purge confirmed the object is gone. */
    case Verified = 'verified';

    /** Search index, realtime and revalidation endpoints have also acknowledged. */
    case Propagated = 'propagated';
}
