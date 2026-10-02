<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The place of one grant that has not ended (PRD 5.10): an actor holds a role on a node at most
 * once, which a partial unique index keeps. grant.assign reads it as absent, so the commit takes
 * its advisory lock, and two assigns of one actor, role and node with different grant ids commit
 * one after the other and the second ends in version_conflict, not in a unique violation.
 */
#[Internal]
final readonly class GrantSlotRef implements AggregateRef
{
    public const string KIND = 'grant_slot';

    public function __construct(
        public ActorId $actor,
        public RoleId $role,
        public NodeId $node,
    ) {}

    /**
     * "grant_slot:" and the actor, the role and the node, separated by colons.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return sprintf('%s:%s:%s:%s', self::KIND, $this->actor->toString(), $this->role->toString(), $this->node->toString());
    }
}
