<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The links of actors to IdP identities (PRD 5.16, "Koblinger"): an IdP identity, its connection,
 * issuer and subject, points at one actor. The login policy reads them to refuse a local login of
 * an actor linked to an authoritative connection (invariant 38).
 */
#[Internal]
interface IdpLinks
{
    /**
     * The connections the actor is linked to through an IdP identity, each once, sorted by name.
     *
     * @return list<ConnectionId>
     */
    public function connectionsOf(ActorId $actor): array;
}
