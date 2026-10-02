<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\Subject;

/**
 * The state of a SCIM group (RFC 7643 4.2) that the identity provider asks for, as a create or a
 * replace sends it: its displayName, unique within its connection without regard to case, an
 * optional externalId, and its members, each the id of a user of the same connection, each once.
 * Its memberships become grants through the group-to-role mapping (PRD 5.16).
 */
#[Experimental]
final readonly class ScimGroup
{
    /** @var list<ScimResourceId> sorted by id */
    public array $members;

    /**
     * @param  list<ScimResourceId>  $members
     *
     * @throws InvalidIdentity when a member is named twice
     */
    public function __construct(public DisplayName $displayName, public ?Subject $externalId = null, array $members = [])
    {
        $this->members = MembershipChange::sorted($members, 'members of a SCIM group');
    }

    /**
     * Whether the two have the same displayName for uniqueness, without regard to case.
     */
    public function sameNameAs(self $other): bool
    {
        return mb_strtolower($this->displayName->value, 'UTF-8') === mb_strtolower($other->displayName->value, 'UTF-8');
    }

    /**
     * The same group with the given members.
     *
     * @param  list<ScimResourceId>  $members
     */
    public function withMembers(array $members): self
    {
        return new self($this->displayName, $this->externalId, $members);
    }

    /**
     * The group's canonical text, the desired state hashed into a change's idempotency key.
     */
    public function canonical(): string
    {
        return ScimIdempotencyKey::canonical(
            'group',
            $this->displayName->value,
            $this->externalId instanceof Subject ? 'v'.$this->externalId->value : '',
            ...array_map(static fn (ScimResourceId $member): string => $member->value, $this->members),
        );
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }
}
