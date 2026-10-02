<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An IdP identity (PRD 5.16, "Koblinger"): the connection, the issuer and the subject together.
 * It points at one actor. Two identities are the same only when all three are, so the same subject
 * of another issuer, or of the same issuer through another connection, is another identity, and an
 * email address is never part of it.
 */
#[Experimental]
final readonly class IdpIdentity
{
    public function __construct(public ConnectionId $connection, public Issuer $issuer, public Subject $subject) {}

    public function equals(self $other): bool
    {
        return $this->connection->equals($other->connection)
            && $this->issuer->equals($other->issuer)
            && $this->subject->equals($other->subject);
    }
}
