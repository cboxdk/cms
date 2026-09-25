<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Who an idempotency key belongs to (PRD 6.1): an actor, such as a user, a token or an agent, or an
 * ingestion source.
 */
#[Experimental]
enum PrincipalKind: string
{
    case Actor = 'actor';
    case Source = 'source';
}
