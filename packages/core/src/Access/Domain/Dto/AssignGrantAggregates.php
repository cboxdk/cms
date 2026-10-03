<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Cbox\Cms\Core\Access\Domain\GuardedGrant;
use Override;

/**
 * What grant.assign read (PRD 5.10, 6.2 phase 1): the new grant, absent unless a grant has its id;
 * the actor to get it, as the ActorDirectory gave it; the role with its permissions; whether the
 * actor holds the role on the node already (the grant's slot); and the version of the actor's set
 * of grants, which the grant changes. The kernel checks each at commit under its lock, so a role
 * changed, an actor deactivated, a second grant of the slot or another change of the actor's
 * grants meanwhile is version_conflict.
 *
 * The command is authorized on the node in each of the grant's locales, or in every locale, and an
 * allow is held to the escalation guard.
 */
#[Internal]
final readonly class AssignGrantAggregates implements GuardedGrant
{
    /**
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public GrantId $grant,
        public ?AggregateVersion $existing,
        public ?Actor $actor,
        public RoleId $role,
        public ?StoredRole $stored,
        public GrantSlotRef $slot,
        public bool $slotTaken,
        public GrantEffect $effect,
        public ?array $locales,
        public AggregateVersion $actorGrants,
    ) {}

    /**
     * Whether the actor may get a grant: it exists, is staff or a service, and is active (PRD
     * 5.10, 5.16).
     */
    public function receives(): bool
    {
        return $this->actor instanceof Actor
            && in_array($this->actor->class, [ActorClass::Staff, ActorClass::Service], true)
            && $this->actor->state === ActorState::Active;
    }

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->grant, $this->existing),
            new ReadVersion($this->slot->actor, $this->actor instanceof Actor ? new AggregateVersion($this->actor->version) : null),
            new ReadVersion($this->role, $this->stored?->version),
            new ReadVersion($this->slot, $this->slotTaken ? AggregateVersion::first() : null),
            new ReadVersion(new ActorGrantsRef($this->slot->actor), $this->actorGrants),
        );
    }

    /**
     * The grant's node in each of its locales, or in every locale.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return self::scope($this->slot->node, $this->locales);
    }

    /**
     * An allow of a role that exists; a deny takes rights away and is not held to the guard.
     */
    #[Override]
    public function escalation(): ?RoleGrant
    {
        if ($this->effect !== GrantEffect::Allow || ! $this->stored instanceof StoredRole) {
            return null;
        }

        return new RoleGrant($this->role, $this->stored->ceiling, $this->stored->permissions, $this->slot->node, $this->locales);
    }

    /**
     * The node in each locale, or in every locale for null.
     *
     * @param  list<Locale>|null  $locales
     */
    public static function scope(NodeId $node, ?array $locales): AuthorizationScope
    {
        if ($locales === null || $locales === []) {
            return AuthorizationScope::on(new AuthorizationTarget($node));
        }

        return AuthorizationScope::on(...array_map(static fn (Locale $locale): AuthorizationTarget => new AuthorizationTarget($node, $locale), $locales));
    }
}
