<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\Login\Subject;

/**
 * The state of a SCIM user (RFC 7643 4.1) that the identity provider asks for, as a create or a
 * replace sends it. A user is a staff actor (PRD 5.16).
 *
 * externalId is the key: the connection declares that it carries the same immutable value as a
 * claim of the ID token, sub by default, so a user created through SCIM is the IdP identity
 * (connection, issuer, subject) and the first login finds its actor. It never changes, and an email
 * address is never the key. userName, the display name and the email address are personal data the
 * connection owns; active=false deactivates the actor and active=true reactivates it.
 */
#[Experimental]
final readonly class ScimUser
{
    public function __construct(
        public Subject $externalId,
        public ScimUserName $userName,
        public ?DisplayName $displayName,
        public ?EmailAddress $email,
        public bool $active = true,
    ) {}

    /**
     * The same user with active set, as a PATCH of active asks for.
     */
    public function withActive(bool $active): self
    {
        return new self($this->externalId, $this->userName, $this->displayName, $this->email, $active);
    }

    /**
     * The user's canonical text, the desired state hashed into a change's idempotency key.
     */
    public function canonical(): string
    {
        return ScimIdempotencyKey::canonical(
            'user',
            $this->externalId->value,
            $this->userName->value,
            $this->displayName instanceof DisplayName ? 'v'.$this->displayName->value : '',
            $this->email instanceof EmailAddress ? 'v'.$this->email->value : '',
            $this->active ? 'active' : 'inactive',
        );
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }
}
