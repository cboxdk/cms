<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Override;

/**
 * Version 1 of the payload of grant.changed: the actor whose access changed, the role and the node
 * of the grant.
 */
#[Experimental]
final readonly class GrantChangedV1 implements EventPayload
{
    public function __construct(
        public ActorId $actor,
        public RoleId $role,
        public NodeId $node,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('actor', EventDatum::identifier($this->actor))
            ->with('role', EventDatum::identifier($this->role))
            ->with('node', EventDatum::identifier($this->node));
    }
}
