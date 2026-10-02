<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ActorId;
use Override;

/**
 * Version 1 of the payload of actor.registered: the actor, its class, the person responsible for a
 * service actor (null for a staff actor), and the credential generation it starts at.
 */
#[Experimental]
final readonly class ActorRegisteredV1 implements EventPayload
{
    public function __construct(
        public ActorId $actor,
        public ActorClass $class,
        public ?ActorId $responsible,
        public int $credentialGeneration,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('actor', EventDatum::identifier($this->actor))
            ->with('class', EventDatum::enum($this->class))
            ->with('responsible', $this->responsible instanceof ActorId ? EventDatum::identifier($this->responsible) : EventDatum::null())
            ->with('credential_generation', EventDatum::integer($this->credentialGeneration));
    }
}
