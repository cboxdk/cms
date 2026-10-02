<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\ActorId;
use Override;

/**
 * Version 1 of the payload of actor.activated: the actor that became active.
 */
#[Experimental]
final readonly class ActorActivatedV1 implements EventPayload
{
    public function __construct(public ActorId $actor) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()->with('actor', EventDatum::identifier($this->actor));
    }
}
