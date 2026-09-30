<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The oldest transactions on the primary that hold back the horizons (PRD 4.2, 7.4): the oldest
 * that holds a transaction id (backend_xid) on the whole server, which holds the event horizon,
 * because transaction ids are shared by every database; and the oldest session of the current
 * database that holds a snapshot (backend_xmin), which holds vacuum there.
 *
 * Postgres shows the start of another role's transaction only to members of that role, and the
 * app role is a member of none (postgres.app_role), so the sessions of other roles that hold an id
 * or a snapshot are counted and named, without an age.
 */
#[Internal]
final readonly class OpenTransactions
{
    /**
     * @param  HeldTransaction|null  $oldestXid  the oldest measured session that holds a transaction id, or null when none does
     * @param  HeldTransaction|null  $oldestSnapshot  the oldest measured session of the current database that holds a snapshot, or null when none does
     * @param  int  $unmeasured  how many sessions of other roles hold a transaction id, or a snapshot in the current database, with no age the app role may see
     * @param  list<string>  $unmeasuredRoles  their roles, sorted, each once
     */
    public function __construct(
        public ?HeldTransaction $oldestXid,
        public ?HeldTransaction $oldestSnapshot,
        public int $unmeasured = 0,
        public array $unmeasuredRoles = [],
    ) {}
}
