<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Database\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A table privilege of Postgres 17, the minimum version (GUARDRAILS 1.2). The value is the word
 * GRANT and REVOKE take and aclexplode() reports as privilege_type.
 *
 * Addon migrations narrow their tables with TablePrivileges::limitTo(), so it is not internal.
 */
#[Experimental]
enum TablePrivilege: string
{
    case Select = 'SELECT';

    case Insert = 'INSERT';

    case Update = 'UPDATE';

    case Delete = 'DELETE';

    case Truncate = 'TRUNCATE';

    case References = 'REFERENCES';

    case Trigger = 'TRIGGER';

    /** VACUUM, ANALYZE, CLUSTER, REFRESH MATERIALIZED VIEW, REINDEX and LOCK TABLE; Postgres 17. */
    case Maintain = 'MAINTAIN';
}
