<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How much of a type's history is kept (PRD 3.1, 5), the capability `history` of its blueprint:
 * every revision, the audit trail only, or nothing.
 */
#[Experimental]
enum History: string
{
    case Full = 'full';
    case AuditOnly = 'audit-only';
    case None = 'none';
}
