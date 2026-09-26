<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How much of a type's history is kept (PRD 3.1, 5): every revision, the audit trail only, or
 * nothing. The capability `history` of the blueprint schema v1.
 */
#[Internal]
enum History: string
{
    case Full = 'full';

    case AuditOnly = 'audit-only';

    case None = 'none';
}
