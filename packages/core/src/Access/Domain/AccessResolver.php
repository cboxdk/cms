<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\Principal;

/**
 * Turns a verified principal into its AccessContext and sets it as the actor context of the
 * caller's open transaction, which row level security reads (PRD 5.10, 6.2). The anonymous
 * principal gets AccessContext::anonymous(); an actor gets the context its grants compile to. The
 * context ends with the transaction, so nothing of it is left for the next call on the connection.
 */
#[Internal]
interface AccessResolver
{
    /**
     * @throws TransactionRequired when the caller has no transaction open; nothing is set
     */
    public function resolve(Principal $principal): AccessContext;
}
